<?php
declare(strict_types=1);

// Telegram -> GitHub Actions bridge.
// Diagnostic log: kanban-private/telegram-webhook.log (directory is denied by .htaccess).
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$webhookStartedAt = microtime(true);
try {
    $webhookRequestId = bin2hex(random_bytes(8));
} catch (Throwable $e) {
    $webhookRequestId = str_replace('.', '', uniqid('req', true));
}
$webhookLogPath = __DIR__ . '/kanban-private/telegram-webhook.log';

function webhook_mask_id($value): string
{
    $value = (string)$value;
    if ($value === '') {
        return '[empty]';
    }
    $last = substr($value, -4);
    return str_repeat('*', max(0, strlen($value) - 4)) . $last;
}

function webhook_log(string $stage, array $context = []): void
{
    global $webhookStartedAt, $webhookRequestId, $webhookLogPath;

    $record = [
        'time_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'request_id' => $webhookRequestId ?? 'unavailable',
        'elapsed_ms' => isset($webhookStartedAt) ? (int)round((microtime(true) - $webhookStartedAt) * 1000) : null,
        'stage' => $stage,
        'context' => $context,
    ];
    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        $line = '{"stage":"log_encoding_failed"}';
    }

    $directory = dirname($webhookLogPath);
    if (!is_dir($directory) || !is_writable($directory)) {
        error_log('Telegram webhook [' . ($webhookRequestId ?? 'unknown') . '] cannot write private diagnostic log.');
        return;
    }

    // Keep a small rolling log so diagnostics cannot fill the hosting account.
    if (is_file($webhookLogPath) && @filesize($webhookLogPath) > 2 * 1024 * 1024) {
        @rename($webhookLogPath, $webhookLogPath . '.1');
    }

    $written = @file_put_contents($webhookLogPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    if ($written === false) {
        error_log('Telegram webhook [' . ($webhookRequestId ?? 'unknown') . '] failed to append private diagnostic log.');
        return;
    }
    @chmod($webhookLogPath, 0600);
}

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        // Also record suppressed warnings from network/file operations.
        webhook_log('php.warning.suppressed', [
            'severity' => $severity,
            'message' => substr((string)$message, 0, 500),
            'file' => basename((string)$file),
            'line' => (int)$line,
        ]);
        return true;
    }
    webhook_log('php.warning', [
        'severity' => $severity,
        'message' => substr((string)$message, 0, 500),
        'file' => basename((string)$file),
        'line' => (int)$line,
    ]);
    return true;
});

register_shutdown_function(function () {
    $lastError = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (is_array($lastError) && in_array($lastError['type'], $fatalTypes, true)) {
        webhook_log('php.fatal', [
            'type' => $lastError['type'],
            'message' => substr((string)$lastError['message'], 0, 700),
            'file' => basename((string)$lastError['file']),
            'line' => (int)$lastError['line'],
        ]);
    }
    webhook_log('request.shutdown', [
        'http_status' => http_response_code(),
        'peak_memory_bytes' => memory_get_peak_usage(true),
    ]);
});

function webhook_reply(int $status, array $payload): void
{
    $responseKind = isset($payload['method']) ? (string)$payload['method'] : 'json';
    webhook_log('response.send', [
        'http_status' => $status,
        'response_kind' => $responseKind,
        'response_bytes' => strlen((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'chat_id_masked' => isset($payload['chat_id']) ? webhook_mask_id($payload['chat_id']) : null,
    ]);
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function send_telegram_message($chatId, string $message): void
{
    // Telegram executes this Bot API method from the webhook response itself.
    // No outbound Telegram API connection is made by the hosting server.
    webhook_log('telegram.reply.queued', [
        'method' => 'sendMessage',
        'chat_id_masked' => webhook_mask_id($chatId),
        'message_length' => strlen($message),
    ]);
    webhook_reply(200, [
        'method' => 'sendMessage',
        'chat_id' => (string)$chatId,
        'text' => $message,
        'disable_web_page_preview' => true,
    ]);
}

function dispatch_github_workflow(array $config, string $task, string $chatId, string $userId): int
{
    $owner = (string)($config['github_owner'] ?? '');
    $repo = (string)($config['github_repo'] ?? '');
    $workflow = (string)($config['github_workflow'] ?? 'telegram-codex.yml');
    $ref = (string)($config['github_ref'] ?? 'main');
    $token = (string)($config['github_token'] ?? '');

    webhook_log('github.dispatch.validate_config', [
        'owner_present' => $owner !== '',
        'repo_present' => $repo !== '',
        'token_present' => $token !== '',
        'workflow' => $workflow,
        'ref' => $ref,
    ]);

    if ($owner === '' || $repo === '' || $token === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $workflow)) {
        webhook_log('github.dispatch.config_invalid');
        return -1;
    }

    $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo)
        . '/actions/workflows/' . rawurlencode($workflow) . '/dispatches';
    $body = json_encode([
        'ref' => $ref,
        'inputs' => [
            'task' => $task,
            'telegram_chat_id' => $chatId,
            'telegram_user_id' => $userId,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    webhook_log('github.dispatch.begin', [
        'host' => 'api.github.com',
        'body_bytes' => strlen((string)$body),
        'timeout_seconds' => 8,
    ]);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . $token,
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: kanban-telegram-bridge',
                'Content-Type: application/json',
            ]) . "\r\n",
            'content' => $body,
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    $networkError = error_get_last();
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
            $status = (int)$matches[1];
        }
    }

    webhook_log('github.dispatch.finished', [
        'http_status' => $status,
        'response_received' => $response !== false,
        'response_bytes' => $response === false ? 0 : strlen($response),
        'network_error' => $response === false && is_array($networkError) ? substr((string)$networkError['message'], 0, 500) : null,
    ]);

    if ($response === false || !in_array($status, [200, 204], true)) {
        error_log('Telegram webhook: GitHub workflow dispatch failed with HTTP ' . $status . '.');
    }
    return $status;
}

webhook_log('request.start', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '[missing]',
    'content_length' => isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : null,
    'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160),
]);

$configPath = __DIR__ . '/kanban-private/telegram-config.php';
webhook_log('config.load.begin', [
    'config_exists' => is_file($configPath),
    'config_readable' => is_readable($configPath),
    'log_directory_writable' => is_writable(dirname($webhookLogPath)),
]);
if (!is_file($configPath) || !is_readable($configPath)) {
    webhook_log('config.load.failed', ['reason' => 'missing_or_unreadable']);
    webhook_reply(503, ['ok' => false, 'error' => 'Telegram integration is not configured.']);
}

try {
    $config = require $configPath;
} catch (Throwable $e) {
    webhook_log('config.load.exception', [
        'exception' => get_class($e),
        'message' => substr($e->getMessage(), 0, 500),
    ]);
    webhook_reply(500, ['ok' => false, 'error' => 'Private Telegram configuration could not be loaded.']);
}
webhook_log('config.load.success', [
    'is_array' => is_array($config),
    'keys_present' => is_array($config) ? array_values(array_intersect(
        ['telegram_bot_token', 'telegram_webhook_secret', 'telegram_allowed_user_ids', 'github_token', 'github_owner', 'github_repo', 'github_workflow', 'github_ref'],
        array_keys($config)
    )) : [],
]);

if (!is_array($config)) {
    webhook_log('config.validation.failed', ['reason' => 'config_not_array']);
    webhook_reply(500, ['ok' => false, 'error' => 'Invalid private Telegram configuration.']);
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
webhook_log('request.method.checked', ['method' => $method]);
if ($method !== 'POST') {
    webhook_log('request.rejected', ['reason' => 'method_not_allowed']);
    webhook_reply(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

$expectedSecret = (string)($config['telegram_webhook_secret'] ?? '');
$receivedSecret = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
$secretOk = $expectedSecret !== '' && $receivedSecret !== '' && hash_equals($expectedSecret, $receivedSecret);
webhook_log('webhook_secret.checked', [
    'configured' => $expectedSecret !== '',
    'header_present' => $receivedSecret !== '',
    'match' => $secretOk,
]);
if (!$secretOk) {
    webhook_log('request.rejected', ['reason' => 'webhook_secret_mismatch']);
    webhook_reply(403, ['ok' => false, 'error' => 'Forbidden.']);
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
webhook_log('request.body.read.begin', ['content_length' => $contentLength]);
if ($contentLength < 1 || $contentLength > 65536) {
    webhook_log('request.rejected', ['reason' => 'invalid_content_length', 'content_length' => $contentLength]);
    webhook_reply(413, ['ok' => false, 'error' => 'Invalid request size.']);
}
$raw = file_get_contents('php://input');
webhook_log('request.body.read.finished', ['body_bytes' => strlen((string)$raw)]);
$update = json_decode($raw ?: '', true);
if (!is_array($update)) {
    webhook_log('request.json.invalid', ['json_error' => json_last_error_msg()]);
    webhook_reply(400, ['ok' => false, 'error' => 'Invalid JSON.']);
}
webhook_log('request.json.valid', [
    'update_id' => isset($update['update_id']) ? (int)$update['update_id'] : null,
    'update_keys' => array_slice(array_keys($update), 0, 12),
]);

$message = $update['message'] ?? null;
if (!is_array($message)) {
    webhook_log('update.ignored', ['reason' => 'no_message_object']);
    webhook_reply(200, ['ok' => true, 'ignored' => true]);
}
$chat = $message['chat'] ?? [];
$from = $message['from'] ?? [];
if (!is_array($chat) || !is_array($from) || ($chat['type'] ?? '') !== 'private'
    || !isset($chat['id'], $from['id'])) {
    webhook_log('update.ignored', [
        'reason' => 'not_private_or_missing_ids',
        'chat_type' => is_array($chat) ? ($chat['type'] ?? null) : null,
    ]);
    webhook_reply(200, ['ok' => true, 'ignored' => true]);
}

$chatId = (string)$chat['id'];
$userId = (string)$from['id'];
$text = trim((string)($message['text'] ?? ''));
$commandKind = $text === '/start' ? 'start' : ($text === '/help' ? 'help' : ($text === '' ? 'empty' : 'text'));
webhook_log('message.received', [
    'update_id' => isset($update['update_id']) ? (int)$update['update_id'] : null,
    'message_id' => isset($message['message_id']) ? (int)$message['message_id'] : null,
    'chat_type' => $chat['type'] ?? null,
    'chat_id_masked' => webhook_mask_id($chatId),
    'user_id_masked' => webhook_mask_id($userId),
    'text_kind' => $commandKind,
    'text_length' => strlen($text),
]);

$allowedIds = array_map('strval', (array)($config['telegram_allowed_user_ids'] ?? []));
$authorized = in_array($userId, $allowedIds, true) && $chatId === $userId;
webhook_log('authorization.checked', [
    'authorized' => $authorized,
    'allowed_user_count' => count($allowedIds),
    'user_id_masked' => webhook_mask_id($userId),
]);
if (!$authorized) {
    send_telegram_message($chatId, 'Пока нет доступа к этому боту. Ваш Telegram ID: ' . $userId . '. Передайте этот номер владельцу для добавления доступа.');
}

if ($text === '/start' || $text === '/help') {
    webhook_log('message.route', ['route' => $text === '/start' ? 'start' : 'help']);
    send_telegram_message($chatId,
        "Привет! Я помощник сайта zevnull.ru.\n\nНапиши обычным сообщением, что нужно изменить на сайте. Например:\n«Сделай заголовок главной страницы крупнее и поменяй текст приветствия».\n\nЯ передам задачу ИИ-агенту Рег.облака. Он изучит сайт, подготовит изменение, проверит его и опубликует, если проверки пройдут."
    );
}

if ($text === '') {
    webhook_log('message.route', ['route' => 'empty_text']);
    send_telegram_message($chatId, 'Пока принимаю только текстовые задачи. Напиши словами, что нужно изменить.');
}
if (strlen($text) > 3000) {
    webhook_log('message.route', ['route' => 'text_too_long', 'text_length' => strlen($text)]);
    send_telegram_message($chatId, 'Задача слишком длинная. Разбей её на несколько сообщений (до 3000 символов каждое).');
}

webhook_log('message.route', ['route' => 'github_dispatch']);
$dispatchStatus = dispatch_github_workflow($config, $text, $chatId, $userId);
if (!in_array($dispatchStatus, [200, 204], true)) {
    if ($dispatchStatus === -1) {
        $reason = 'Проверь github_token, github_owner, github_repo и github_workflow в приватном конфиге.';
    } elseif ($dispatchStatus === 0) {
        $reason = 'Хостинг не смог подключиться к api.github.com.';
    } elseif ($dispatchStatus === 401) {
        $reason = 'GitHub не принял токен: проверь, не истёк ли он и правильно ли скопирован.';
    } elseif ($dispatchStatus === 403) {
        $reason = 'GitHub отказал в доступе. Для токена нужно Actions: Read and write в репозитории zevnull00/kanban-goals.';
    } elseif ($dispatchStatus === 404) {
        $reason = 'GitHub не нашёл workflow или репозиторий для этого токена. Проверь github_owner, github_repo и github_workflow.';
    } elseif ($dispatchStatus === 422) {
        $reason = 'GitHub отклонил параметры запуска workflow. Проверь имя workflow и ветку main.';
    } else {
        $reason = 'Проверь настройки GitHub и приватную конфигурацию.';
    }
    send_telegram_message($chatId, 'Не удалось запустить workflow GitHub (HTTP ' . $dispatchStatus . '). ' . $reason);
}

send_telegram_message($chatId, 'Принял задачу. Запускаю ИИ-агента Рег.облака: он изучит сайт, внесёт изменение и проверит его. Напишу сюда, когда проверки и публикация завершатся.');
