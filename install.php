<?php
/**
 * Обработчик установки локального приложения Битрикс24.
 *
 * При регистрации приложения в Битрикс24 (Приложения → Разработчикам → Локальные приложения)
 * укажите этот файл как "Путь к обработчику".
 *
 * Параметры регистрации:
 *   Тип: Серверное
 *   Название: Договоры - Туган Як
 *   Путь к обработчику: https://ваш-хост.ру/contract-app/install.php
 *   Права: disk, tasks, user, app
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$logFile = __DIR__ . '/storage/install.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
file_put_contents($logFile, date('Y-m-d H:i:s') . ' - ' . file_get_contents('php://input') . PHP_EOL, FILE_APPEND);

$data = $_REQUEST;

$required = ['domain', 'auth_token', 'refresh_token'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing required field: $field"]);
        exit;
    }
}

// Сохраняем токены
$dir = dirname(TOKEN_FILE);
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$tokens = [
    'domain' => $data['domain'],
    'auth_token' => $data['auth_token'],
    'refresh_token' => $data['refresh_token'],
    'client_endpoint' => 'https://' . $data['domain'] . '/rest/',
    'server_endpoint' => 'https://' . $data['domain'] . '/',
    'member_id' => $data['member_id'] ?? '',
    'user_id' => $data['user_id'] ?? null,
    'status' => 'installed',
    'installed_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
];

file_put_contents(TOKEN_FILE, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

file_put_contents($logFile, date('Y-m-d H:i:s') . ' - Установка успешна: ' . $data['domain'] . PHP_EOL, FILE_APPEND);

// Перенаправляем на главную страницу приложения
$redirectUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . dirname($_SERVER['SCRIPT_NAME']) . '/index.php';

header('Location: ' . $redirectUrl);
exit;
