<?php
header('Content-Type: application/json; charset=utf-8');

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$accountId = isset($input['account_id']) ? (int) $input['account_id'] : 0;
$qrContent = isset($input['qr_content']) ? trim((string) $input['qr_content']) : '';

if ($accountId <= 0 || $qrContent === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid account_id or qr_content']);
    exit;
}

try {
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    if (empty($host)) {
        echo json_encode(['success' => false, 'error' => 'Domain not determined']);
        exit;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $hookUrl = $scheme . '://' . $host . '/wh/qr_auth.php';

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode(['account_id' => $accountId, 'qr_content' => $qrContent], JSON_UNESCAPED_UNICODE),
            'timeout' => 30,
            'ignore_errors' => true
        ]
    ]);
    $response = @file_get_contents($hookUrl, false, $ctx);

    if ($response === false) {
        error_log("qr_auth: failed to reach hook " . $hookUrl);
        echo json_encode(['success' => false, 'error' => 'Hook request failed']);
        exit;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['success'])) {
        $err = $data['error'] ?? 'Invalid hook response';
        echo json_encode(['success' => false, 'error' => $err]);
        exit;
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("qr_auth error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
