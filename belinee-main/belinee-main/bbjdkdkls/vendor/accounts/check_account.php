<?php
header('Content-Type: application/json; charset=utf-8');

session_start();

$accountId = isset($_GET['account_id']) ? (int) $_GET['account_id'] : 0;
if ($accountId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid account_id']);
    exit;
}

try {
    // Парсим домен из текущего запроса
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    if (empty($host)) {
        echo json_encode(['success' => false, 'error' => 'Domain not determined']);
        exit;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $hookUrl = $scheme . '://' . $host . '/wh/check_account.php?account_id=' . $accountId;

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 30,
            'ignore_errors' => true
        ]
    ]);
    $response = @file_get_contents($hookUrl, false, $ctx);

    if ($response === false) {
        error_log("check_account: failed to reach hook " . $hookUrl);
        echo json_encode(['success' => false, 'error' => 'Hook request failed']);
        exit;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['action_id'])) {
        $err = $data['error'] ?? 'Invalid hook response';
        echo json_encode(['success' => false, 'error' => $err]);
        exit;
    }

    echo json_encode([
        'success' => 'ok',
        'action_id' => (int) $data['action_id']
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("check_account error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
