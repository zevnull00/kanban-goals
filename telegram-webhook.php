<?php
declare(strict_types=1);

// Telegram -> GitHub Actions bridge. Private credentials are loaded from kanban-private/telegram-config.php.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function webhook_reply(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function send_telegram_message($chatId, string $message): void
{
    // Telegram supports executing a Bot API method from the webhook response.
    // This avoids a second outbound connection from REG.RU back to Telegram.
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

    if ($owner === '' || $repo === '' || $token === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $workflow)) {
        error_log('Telegram webhook: GitHub integration is not configured.');
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
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
            $status = (int)$matches[1];
        }
    }
    if ($response === false || !in_array($status, [200, 204], true)) {
        error_log('Telegram webhook: GitHub workflow dispatch failed with HTTP ' . $status . '.');
    }
    return $status;
}

$configPath = __DIR__ . '/kanban-private/telegram-config.php';
if (!is_file($configPath)) {
    webhook_reply(503, ['ok' => false, 'error' => 'Telegram integration is not configured.']);
}
$config = require $configPath;
if (!is_array($config)) {
    webhook_reply(500, ['ok' => false, 'error' => 'Invalid private Telegram configuration.']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    webhook_reply(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

$expectedSecret = (string)($config['telegram_webhook_secret'] ?? '');
$receivedSecret = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if ($expectedSecret === '' || $receivedSecret === '' || !hash_equals($expectedSecret, $receivedSecret)) {
    webhook_reply(403, ['ok' => false, 'error' => 'Forbidden.']);
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength < 1 || $contentLength > 65536) {
    webhook_reply(413, ['ok' => false, 'error' => 'Invalid request size.']);
}
$raw = file_get_contents('php://input');
$update = json_decode($raw ?: '', true);
if (!is_array($update)) {
    webhook_reply(400, ['ok' => false, 'error' => 'Invalid JSON.']);
}

$message = $update['message'] ?? null;
if (!is_array($message)) {
    webhook_reply(200, ['ok' => true, 'ignored' => true]);
}
$chat = $message['chat'] ?? [];
$from = $message['from'] ?? [];
if (!is_array($chat) || !is_array($from) || ($chat['type'] ?? '') !== 'private'
    || !isset($chat['id'], $from['id'])) {
    webhook_reply(200, ['ok' => true, 'ignored' => true]);
}

$chatId = (string)$chat['id'];
$userId = (string)$from['id'];
$allowedIds = array_map('strval', (array)($config['telegram_allowed_user_ids'] ?? []));
if (!in_array($userId, $allowedIds, true) || $chatId !== $userId) {
    send_telegram_message($chatId, 'Пока нет доступа к этому боту. Ваш Telegram ID: ' . $userId . '. Передайте этот номер владельцу для добавления доступа.');
    webhook_reply(200, ['ok' => true, 'ignored' => true]);
}

$text = trim((string)($message['text'] ?? ''));
if ($text === '/start' || $text === '/help') {
    send_telegram_message($chatId,
        "Привет! Я помощник сайта zevnull.ru.\n\nНапиши обычным сообщением, что нужно изменить на сайте. Например:\n«Сделай заголовок главной страницы крупнее и поменяй текст приветствия».\n\nЯ передам задачу ИИ-агенту Рег.облака. Он изучит сайт, подготовит изменение, проверит его и опубликует, если проверки пройдут."
    );
    webhook_reply(200, ['ok' => true]);
}
if ($text === '') {
    send_telegram_message($chatId, 'Пока принимаю только текстовые задачи. Напиши словами, что нужно изменить.');
    webhook_reply(200, ['ok' => true]);
}
if (strlen($text) > 3000) {
    send_telegram_message($chatId, 'Задача слишком длинная. Разбей её на несколько сообщений (до 3000 символов каждое).');
    webhook_reply(200, ['ok' => true]);
}

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

send_telegram_message($chatId, 'Принял задачу. Запускаю ИИ-агента Рег.облака: он изучит сайт, внесёт изменение и проверит его. Напишу сюда, когда проверка и публикация завершатся.');
webhook_reply(200, ['ok' => true, 'accepted' => true]);
