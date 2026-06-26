<?php
// Проверяем авторизацию
require_once __DIR__ . '/vendor/database/pdo_connect.php';

session_start();

$isLoggedIn = false;
$user = null;

if (isset($_COOKIE['auth_token'])) {
    try {
        $pdo = pdo_connect();
        $token = $_COOKIE['auth_token'];
        
        $stmt = $pdo->prepare("
            SELECT u.id, u.login, u.email, u.status 
            FROM users u
            INNER JOIN users_sessions s ON u.id = s.user_id
            WHERE s.token = ? 
            AND s.status = 1 
            AND u.status = 1
            AND (s.expires_at IS NULL OR s.expires_at > NOW())
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if ($user) {
            $isLoggedIn = true;
        }
    } catch (Exception $e) {
        error_log("Auth check error: " . $e->getMessage());
    }
}

// Если авторизован - редирект на dashboard
if ($isLoggedIn) {
    header('Location: /dashboard.php');
    exit;
}

// Если не авторизован - показываем форму авторизации
require_once __DIR__ . '/models/auth.php';
?>
