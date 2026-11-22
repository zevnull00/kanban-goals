<?php
declare(strict_types=1);

// Серверное API канбана. Файл данных размещается за пределами публичного каталога сайта.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function api_response(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_response(405, ['ok' => false, 'message' => 'Method not allowed']);
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 4 * 1024 * 1024) {
    api_response(413, ['ok' => false, 'message' => 'Слишком большой запрос.']);
}

$rawInput = file_get_contents('php://input');
try {
    $input = json_decode($rawInput ?: '{}', true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    api_response(400, ['ok' => false, 'message' => 'Некорректный JSON.']);
}
if (!is_array($input)) {
    api_response(400, ['ok' => false, 'message' => 'Некорректный формат запроса.']);
}

$configPath = __DIR__ . '/kanban-private/config.php';
if (!is_file($configPath)) {
    api_response(503, ['ok' => false, 'message' => 'Не найден файл kanban-private/config.php. Загрузите файл конфигурации на хостинг.']);
}
require_once $configPath;
if (!defined('KANBAN_PASSWORD_HASH') || !is_string(KANBAN_PASSWORD_HASH) || KANBAN_PASSWORD_HASH === '') {
    api_response(500, ['ok' => false, 'message' => 'Не настроен серверный пароль.']);
}

$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if (!$isHttps) {
    api_response(400, ['ok' => false, 'message' => 'Для безопасного входа открой сайт по HTTPS.']);
}
session_name('kanban_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

function storage_directory(): string
{
    // Каталог создаётся рядом с публичной папкой сайта, но не внутри неё.
    $directory = dirname(__DIR__) . '/.kanban-data-' . substr(hash('sha256', __DIR__), 0, 18);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать закрытый каталог данных рядом с папкой сайта.');
    }
    @chmod($directory, 0700);
    return $directory;
}

function normalize_payload($data): array
{
    if (!is_array($data)) {
        throw new InvalidArgumentException('Данные канбана имеют неверный формат.');
    }
    $result = [
        'schemaVersion' => isset($data['schemaVersion']) && is_numeric($data['schemaVersion']) ? (int)$data['schemaVersion'] : 3,
        'updatedAt' => isset($data['updatedAt']) && is_string($data['updatedAt']) ? $data['updatedAt'] : gmdate('c'),
    ];
    foreach (['cards', 'weekly', 'directionTasks', 'projects', 'deadlines', 'customNotes'] as $key) {
        $value = $data[$key] ?? [];
        if (!is_array($value)) {
            throw new InvalidArgumentException('Некорректный раздел данных: ' . $key);
        }
        $result[$key] = $value;
    }
    return $result;
}

function read_data_locked(string $directory): ?array
{
    $file = $directory . '/kanban.json';
    if (!is_file($file)) {
        return null;
    }
    $raw = file_get_contents($file);
    if ($raw === false) {
        throw new RuntimeException('Не удалось прочитать файл данных на сервере.');
    }
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('Файл данных на сервере повреждён. Не перезаписывайте его; сделайте резервную копию.');
    }
    return normalize_payload($data);
}

function write_data_locked(string $directory, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $temporaryFile = $directory . '/kanban-' . bin2hex(random_bytes(8)) . '.tmp';
    if (file_put_contents($temporaryFile, $json, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать данные на хостинг. Проверьте права на каталог хранения.');
    }
    @chmod($temporaryFile, 0600);
    if (!@rename($temporaryFile, $directory . '/kanban.json')) {
        @unlink($temporaryFile);
        throw new RuntimeException('Не удалось завершить сохранение файла данных на хостинге.');
    }
    @chmod($directory . '/kanban.json', 0600);
}

function with_storage_lock(callable $callback)
{
    $directory = storage_directory();
    $lockFile = fopen($directory . '/storage.lock', 'c');
    if ($lockFile === false) {
        throw new RuntimeException('Не удалось открыть блокировку хранилища.');
    }
    @chmod($directory . '/storage.lock', 0600);
    try {
        if (!flock($lockFile, LOCK_EX)) {
            throw new RuntimeException('Не удалось заблокировать хранилище данных.');
        }
        return $callback($directory);
    } finally {
        flock($lockFile, LOCK_UN);
        fclose($lockFile);
    }
}

$action = (string)($input['action'] ?? '');

if ($action === 'session') {
    api_response(200, ['ok' => true, 'authenticated' => !empty($_SESSION['authenticated'])]);
}

if ($action === 'login') {
    $lockedUntil = (int)($_SESSION['login_locked_until'] ?? 0);
    if ($lockedUntil > time()) {
        api_response(429, ['ok' => false, 'message' => 'Ты не Даша!']);
    }

    $password = $input['password'] ?? '';
    if (!is_string($password) || !password_verify($password, KANBAN_PASSWORD_HASH)) {
        $failures = (int)($_SESSION['login_failures'] ?? 0) + 1;
        if ($failures >= 8) {
            $_SESSION['login_failures'] = 0;
            $_SESSION['login_locked_until'] = time() + 60;
        } else {
            $_SESSION['login_failures'] = $failures;
        }
        usleep(350000);
        api_response(401, ['ok' => false, 'message' => 'Ты не Даша!']);
    }
    unset($_SESSION['login_failures'], $_SESSION['login_locked_until']);
    session_regenerate_id(true);
    $_SESSION['authenticated'] = true;
    api_response(200, ['ok' => true, 'authenticated' => true]);
}

if (empty($_SESSION['authenticated'])) {
    api_response(401, ['ok' => false, 'message' => 'Сессия закончилась. Войдите ещё раз.']);
}

try {
    if ($action === 'load') {
        $result = with_storage_lock(function (string $directory): array {
            $data = read_data_locked($directory);
            return ['initialized' => $data !== null, 'data' => $data];
        });
        api_response(200, ['ok' => true] + $result);
    }

    if ($action === 'initialize') {
        $data = normalize_payload($input['data'] ?? null);
        $result = with_storage_lock(function (string $directory) use ($data): array {
            $current = read_data_locked($directory);
            if ($current !== null) {
                return ['initialized' => true, 'data' => $current, 'created' => false];
            }
            write_data_locked($directory, $data);
            return ['initialized' => true, 'data' => $data, 'created' => true];
        });
        api_response(200, ['ok' => true] + $result);
    }

    if ($action === 'save') {
        $data = normalize_payload($input['data'] ?? null);
        with_storage_lock(function (string $directory) use ($data): void {
            write_data_locked($directory, $data);
        });
        api_response(200, ['ok' => true, 'savedAt' => gmdate('c')]);
    }

    api_response(400, ['ok' => false, 'message' => 'Неизвестная команда API.']);
} catch (Throwable $e) {
    error_log('Kanban API error: ' . $e->getMessage());
    api_response(500, ['ok' => false, 'message' => $e->getMessage()]);
}
