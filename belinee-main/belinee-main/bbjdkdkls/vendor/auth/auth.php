<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../database/pdo_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $login = trim($input['login'] ?? '');
    $password = trim($input['password'] ?? '');
    $captchaToken = trim($input['captchaToken'] ?? '');
    $captchaText = trim($input['captchaText'] ?? '');
    
    if (empty($login) || empty($password)) {
        echo json_encode(['success' => false, 'error' => 'invalid_request']);
        exit;
    }
    
    $pdo = pdo_connect();
    
    // Проверка капчи
    if (!empty($captchaToken) && !empty($captchaText)) {
        $stmt = $pdo->prepare("SELECT captcha_text, status FROM captchas WHERE captcha_token = ? AND status = 1 LIMIT 1");
        $stmt->execute([$captchaToken]);
        $captcha = $stmt->fetch();
        
        if (!$captcha || strtoupper($captcha['captcha_text']) !== strtoupper($captchaText)) {
            // Помечаем капчу как использованную
            $pdo->prepare("UPDATE captchas SET status = 0 WHERE captcha_token = ?")->execute([$captchaToken]);
            
            echo json_encode([
                'success' => false,
                'error' => 'captcha_invalid'
            ]);
            exit;
        }
        
        // Помечаем капчу как использованную
        $pdo->prepare("UPDATE captchas SET status = 0 WHERE captcha_token = ?")->execute([$captchaToken]);
    }
    
    // Проверка пользователя
    $passwordHash = md5($password);
    $stmt = $pdo->prepare("SELECT id, login, email, status FROM users WHERE login = ? AND password = ? AND status = 1 LIMIT 1");
    $stmt->execute([$login, $passwordHash]);
    $user = $stmt->fetch();
    
    if (!$user) {
        echo json_encode(['success' => false, 'error' => 'auth_failed']);
        exit;
    }
    
    // Генерируем токен сессии
    $token = bin2hex(random_bytes(64)); // 128 символов
    
    // Получаем IP и User-Agent
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    // Время жизни сессии (30 дней)
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
    
    // Создаем сессию
    $stmt = $pdo->prepare("INSERT INTO users_sessions (user_id, token, ip_address, user_agent, expires_at, status) VALUES (?, ?, ?, ?, ?, 1)");
    $stmt->execute([$user['id'], $token, $ipAddress, $userAgent, $expiresAt]);
    
    // Устанавливаем куки
    setcookie('auth_token', $token, [
        'expires' => time() + (30 * 24 * 60 * 60), // 30 дней
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    echo json_encode([
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'login' => $user['login'],
            'email' => $user['email']
        ],
        'token' => $token
    ]);
    
} catch (Exception $e) {
    error_log("Auth error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error ' . $e->getMessage()]);
}
?>
