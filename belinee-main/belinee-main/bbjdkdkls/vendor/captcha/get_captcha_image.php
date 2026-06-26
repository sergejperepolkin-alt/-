<?php
// Включаем вывод ошибок для отладки (убрать на продакшене!)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../database/pdo_connect.php';

try {
    $token = $_GET['token'] ?? '';
    
    if (empty($token)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "400 Bad Request: параметр token обязателен";
        exit;
    }
    
    $pdo = pdo_connect();
    
    // Получаем текст капчи по токену
    $stmt = $pdo->prepare("SELECT captcha_text FROM captchas WHERE captcha_token = ? AND status = 1 LIMIT 1");
    $stmt->execute([$token]);
    $captcha = $stmt->fetch();
    
    if (!$captcha) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "404 Not Found: капча не найдена по token=" . htmlspecialchars($token);
        exit;
    }
    
    $text = $captcha['captcha_text'];
    
    // Параметры изображения
    $width = 200;
    $height = 60;
    $fontSize = 24;
    
    // Создаем изображение
    if (!extension_loaded('gd')) {
        throw new Exception('Расширение GD не установлено');
    }
    $image = imagecreatetruecolor($width, $height);
    if ($image === false) {
        throw new Exception('Не удалось создать изображение (imagecreatetruecolor)');
    }
    
    // Цвета
    $bgColor = imagecolorallocate($image, 15, 15, 35); // Темный фон
    $textColor = imagecolorallocate($image, 99, 102, 241); // Индиго
    $noiseColor1 = imagecolorallocate($image, 79, 70, 229);
    $noiseColor2 = imagecolorallocate($image, 129, 140, 248);
    
    // Заливаем фон
    imagefill($image, 0, 0, $bgColor);
    
    // Добавляем шум (линии и точки)
    for ($i = 0; $i < 10; $i++) {
        imageline($image, 
            rand(0, $width), rand(0, $height),
            rand(0, $width), rand(0, $height),
            $noiseColor1
        );
    }
    
    for ($i = 0; $i < 50; $i++) {
        imagesetpixel($image, rand(0, $width), rand(0, $height), $noiseColor2);
    }
    
    // Шрифт (используем встроенный шрифт, можно заменить на TTF)
    $font = 5; // Встроенный шрифт (1-5)
    
    // Вычисляем позицию текста по центру (imagefontwidth/height принимают ID шрифта 1-5)
    $textWidth = imagefontwidth($font) * strlen($text);
    $textHeight = imagefontheight($font);
    $x = (int)(($width - $textWidth) / 2);
    $y = (int)(($height - $textHeight) / 2);
    
    // Рисуем текст (imagestring принимает font ID 1-5)
    imagestring($image, $font, $x, $y, $text, $textColor);
    
    // Если есть TTF шрифт, можно использовать imagettftext
    // $fontPath = __DIR__ . '/arial.ttf';
    // if (file_exists($fontPath)) {
    //     imagettftext($image, $fontSize, 0, $x, $y + $fontSize, $textColor, $fontPath, $text);
    // } else {
    //     imagestring($image, $fontSize, $x, $y, $text, $textColor);
    // }
    
    // Выводим изображение
    header('Content-Type: image/png');
    imagepng($image);
    imagedestroy($image);
    
} catch (Throwable $e) {
    error_log("Captcha image error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Ошибка 500:\n";
    echo $e->getMessage() . "\n\n";
    echo "Файл: " . $e->getFile() . " (строка " . $e->getLine() . ")\n\n";
    echo "Trace:\n" . $e->getTraceAsString();
}
?>
