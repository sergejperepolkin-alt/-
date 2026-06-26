<?php
/**
 * Обработка регистрации
 * POST /vendor/auth/register.php
 * 
 * Получает: { login, password, password2, email }
 * Возвращает: { success: true/false, error?: string }
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../database/pdo_connect.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$login = isset($input['login']) ? trim((string) $input['login']) : '';
$password = isset($input['password']) ? (string) $input['password'] : '';
$password2 = isset($input['password2']) ? (string) $input['password2'] : '';
$email = isset($input['email']) ? trim((string) $input['email']) : '';

// Валидация
if (!$login || !$password) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_request']);
    exit;
}

if ($password !== $password2) {
    echo json_encode(['success' => false, 'error' => 'password_mismatch']);
    exit;
}

if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'error' => 'password_too_short']);
    exit;
}

if (strlen($login) < 3 || strlen($login) > 64) {
    echo json_encode(['success' => false, 'error' => 'login_invalid']);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9_-]+$/', $login)) {
    echo json_encode(['success' => false, 'error' => 'login_invalid']);
    exit;
}

try {
    $pdo = pdo_connect();

    // Проверяем, занят ли логин
    $stmt = $pdo->prepare("SELECT id FROM users WHERE login = ? LIMIT 1");
    $stmt->execute([$login]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'login_taken']);
        exit;
    }

    // Если email указан, проверяем его
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'invalid_email']);
        exit;
    }

    if ($email) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'email_taken']);
            exit;
        }
    }

    // Хешируем пароль
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    // Создаём пользователя
    $stmt = $pdo->prepare("
        INSERT INTO users (login, email, password, status, created_at)
        VALUES (?, ?, ?, 1, NOW())
    ");
    $stmt->execute([$login, $email ?: null, $passwordHash]);
    $userId = $pdo->lastInsertId();

    // Создаём сессию
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

    $stmt = $pdo->prepare("
        INSERT INTO users_sessions (user_id, token, status, expires_at, created_at)
        VALUES (?, ?, 1, ?, NOW())
    ");
    $stmt->execute([$userId, $token, $expiresAt]);

    // Устанавливаем cookie
    setcookie('auth_token', $token, strtotime($expiresAt), '/', '', false, true);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log("Register error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'registration_failed']);
}
?>