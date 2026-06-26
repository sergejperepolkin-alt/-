<?php
/**
 * Обработка входа (авторизация)
 * POST /vendor/auth/auth.php
 * 
 * Получает: { login, password }
 * Возвращает: { success: true/false, error?: string }
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../database/pdo_connect.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$login = isset($input['login']) ? trim((string) $input['login']) : '';
$password = isset($input['password']) ? (string) $input['password'] : '';

if (!$login || !$password) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_request']);
    exit;
}

try {
    $pdo = pdo_connect();

    // Ищем пользователя по логину
    $stmt = $pdo->prepare("SELECT id, login, password, status FROM users WHERE login = ? AND status = 1 LIMIT 1");
    $stmt->execute([$login]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'auth_failed']);
        exit;
    }

    // Проверяем пароль
    if (!password_verify($password, $user['password'])) {
        echo json_encode(['success' => false, 'error' => 'auth_failed']);
        exit;
    }

    // Создаём сессию
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

    $stmt = $pdo->prepare("
        INSERT INTO users_sessions (user_id, token, status, expires_at, created_at)
        VALUES (?, ?, 1, ?, NOW())
    ");
    $stmt->execute([$user['id'], $token, $expiresAt]);

    // Устанавливаем cookie
    setcookie('auth_token', $token, strtotime($expiresAt), '/', '', false, true);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log("Auth error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'auth_failed']);
}
?>