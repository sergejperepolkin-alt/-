<?php
require_once __DIR__ . '/../database/pdo_connect.php';

session_start();

// Удаляем токен из БД
if (isset($_COOKIE['auth_token'])) {
    try {
        $pdo = pdo_connect();
        $token = $_COOKIE['auth_token'];
        
        $stmt = $pdo->prepare("UPDATE users_sessions SET status = 0 WHERE token = ?");
        $stmt->execute([$token]);
    } catch (Exception $e) {
        error_log("Logout error: " . $e->getMessage());
    }
}

// Удаляем куки
setcookie('auth_token', '', [
    'expires' => time() - 3600,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Уничтожаем сессию
session_destroy();

// Редирект на главную
header('Location: /index.php');
exit;
?>
