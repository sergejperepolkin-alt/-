<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../database/pdo_connect.php';

$actionId = isset($_GET['action_id']) ? (int) $_GET['action_id'] : 0;
if ($actionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid action_id']);
    exit;
}

try {
    $pdo = pdo_connect();

    $stmt = $pdo->prepare("
        SELECT action_id, account_id, ended, action_response
        FROM actions
        WHERE action_id = ?
        LIMIT 1
    ");
    $stmt->execute([$actionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Action not found']);
        exit;
    }

    echo json_encode([
        'success' => 'ok',
        'action_info' => [
            'ended' => (int) $row['ended'],
            'action_response' => $row['action_response'] ?? ''
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("get_action error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
