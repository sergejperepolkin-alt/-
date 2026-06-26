<?php
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$accountId = isset($input['account_id']) ? (int) $input['account_id'] : 0;
$qrContent = isset($input['qr_content']) ? trim((string) $input['qr_content']) : '';

if ($accountId <= 0 || $qrContent === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid account_id or qr_content']);
    exit;
}

try {
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    $baseDir = dirname(__DIR__);
    $script = $baseDir . '/jsbackend/qr_auth.js';
    if (file_exists($script)) {
        $qrEscaped = escapeshellarg($qrContent);
        $cmd = sprintf('node %s %d %s > /dev/null 2>&1 &', escapeshellarg($script), $accountId, $qrEscaped);
        exec($cmd);
    }
} catch (Exception $e) {
    error_log("wh/qr_auth error: " . $e->getMessage());
    if (!headers_sent()) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}
