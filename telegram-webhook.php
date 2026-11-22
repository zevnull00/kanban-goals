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

function telegram_api(string $token, string $method, array $payload): array
{
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timeout' => 12,
            'ignore_errors' => true,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        error_log('Telegram webhook: Telegram API request failed.');
        return ['ok' => false];
    }
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : ['ok' => false];
}

function send_telegram_message(array $config, $chatId, string $message): void
{
    $token = (string)($config['telegram_bot_token'] ?? '');
    if ($token !== '') {
        telegram_api($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => $message,
            'disable_web_page_preview' => true,
        ]);
    }
}

function dispatch_github_workflow(array $config, string $task, string $chatId, string $userId): bool
{
    $owner = (string)($config['github_owner'] ?? '');
    $repo = (string)($config['github_repo'] ?? '');
    $workflow = (string)($config['github_workflow'] ?? 'telegram-codex.yml');
    $ref = (string)($config['github_ref'] ?? 'main');
    $token = (string)($config['github_token'] ?? '');

    if ($owner === '' || $repo === '' || $token === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $workflow)) {
        error_log('Telegram webhook: GitHub integration is not configured.');
        return false;
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
            'timeout' => 15,
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
    if ($response === false || $status !== 204) {
        error_log('Telegram webhook: GitHub workflow dispatch failed with HTTP ' . $status . '.');
        return false;
    }
    return true;
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
    send_telegram_message($config, $chatId, 'Пока нет доступа к этому боту. Ваш Telegram ID: ' . $userId . '. Передайте этот номер владельцу для добавления доступа.');
    webhook_reply(200, ['ok' => true, 'ignored' => true]);
}

$text = trim((string)($message['text'] ?? ''));
if ($text === '/start' || $text === '/help') {
    send_telegram_message($config, $chatId,
        "Привет! Я помощник сайта zevnull.ru.\n\nНапиши обычным сообщением, что нужно изменить на сайте. Например:\n«Сделай заголовок главной страницы крупнее и поменяй текст приветствия».\n\nЯ передам задачу Codex, проверю изменения и опубликую сайт, если проверки пройдут."
    );
    webhook_reply(200, ['ok' => true]);
}
if ($text === '') {
    send_telegram_message($config, $chatId, 'Пока принимаю только текстовые задачи. Напиши словами, что нужно изменить.');
    webhook_reply(200, ['ok' => true]);
}
if (strlen($text) > 3000) {
    send_telegram_message($config, $chatId, 'Задача слишком длинная. Разбей её на несколько сообщений (до 3000 символов каждое).');
    webhook_reply(200, ['ok' => true]);
}

if (!dispatch_github_workflow($config, $text, $chatId, $userId)) {
    send_telegram_message($config, $chatId, 'Не удалось запустить обработку задачи в GitHub. Попробуй ещё раз позже или сообщи владельцу бота.');
    webhook_reply(502, ['ok' => false, 'error' => 'GitHub workflow dispatch failed.']);
}

send_telegram_message($config, $chatId, 'Принял задачу. Запускаю Codex: он изучит сайт, внесёт изменение и проверит его. Напишу сюда, когда проверка и публикация завершатся.');
webhook_reply(200, ['ok' => true, 'accepted' => true]);
