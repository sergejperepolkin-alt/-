<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../database/pdo_connect.php';

try {
    $pdo = pdo_connect();
    
    // Генерируем 8-символьный текст капчи (только буквы и цифры, без похожих символов)
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $captchaText = '';
    for ($i = 0; $i < 8; $i++) {
        $captchaText .= $chars[random_int(0, strlen($chars) - 1)];
    }
    
    // Генерируем captcha_id (от 1 до 9999999)
    $captchaId = random_int(1, 9999999);
    
    // Генерируем токен (128 символов: буквы и цифры)
    $tokenChars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $captchaToken = '';
    for ($i = 0; $i < 128; $i++) {
        $captchaToken .= $tokenChars[random_int(0, strlen($tokenChars) - 1)];
    }
    
    // Проверяем, существует ли таблица, если нет - создаем
    $pdo->exec("CREATE TABLE IF NOT EXISTS captchas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        captcha_id INT NOT NULL,
        captcha_token VARCHAR(128) NOT NULL UNIQUE,
        captcha_text VARCHAR(8) NOT NULL,
        status INT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (captcha_token),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Вставляем запись в БД
    $stmt = $pdo->prepare("INSERT INTO captchas (captcha_id, captcha_token, captcha_text, status) VALUES (?, ?, ?, 1)");
    $stmt->execute([$captchaId, $captchaToken, $captchaText]);
    
    echo json_encode([
        'success' => true,
        'captchaInfo' => [
            'captchaToken' => $captchaToken,
            'captchaId' => $captchaId
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Captcha generation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to generate captcha'
    ]);
}
?>
