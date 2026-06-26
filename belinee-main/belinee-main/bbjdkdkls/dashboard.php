<?php
/**
 * Dashboard - главная страница после авторизации
 */

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

if (!$isLoggedIn) {
    header('Location: /');
    exit;
}

// Если авторизован - показываем dashboard
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Invader Panel</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f0f23;
            color: #fff;
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding-bottom: 20px;
        }

        .header h1 {
            font-size: 32px;
            color: #fff;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .user-info span {
            color: #a5b4fc;
        }

        .logout-btn {
            padding: 10px 20px;
            background: rgba(220, 38, 38, 0.8);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .logout-btn:hover {
            background: rgba(220, 38, 38, 1);
            transform: translateY(-2px);
        }

        .content {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(79, 70, 229, 0.2);
            border-radius: 12px;
            padding: 30px;
            backdrop-filter: blur(10px);
        }

        .content h2 {
            margin-bottom: 20px;
            color: #c7d2fe;
        }

        .status-message {
            background: rgba(79, 70, 229, 0.2);
            border: 1px solid rgba(79, 70, 229, 0.5);
            border-radius: 8px;
            padding: 20px;
            color: #c7d2fe;
            margin-top: 20px;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>INVADER PANEL</h1>
            <div class="user-info">
                <span>👤 <?php echo htmlspecialchars($user['login']); ?></span>
                <button class="logout-btn" onclick="logout()">Выход</button>
            </div>
        </div>

        <div class="content">
            <h2>Добро пожаловать! 🎉</h2>
            <p>Вы успешно авторизовались в системе.</p>
            <div class="status-message">
                ✅ Статус: Авторизован<br>
                👤 Логин: <?php echo htmlspecialchars($user['login']); ?><br>
                📧 Email: <?php echo htmlspecialchars($user['email'] ?: 'не указан'); ?>
            </div>
        </div>
    </div>

    <script>
        function logout() {
            document.cookie = 'auth_token=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
            window.location.href = '/';
        }
    </script>
</body>
</html>