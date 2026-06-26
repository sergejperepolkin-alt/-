<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../database/pdo_connect.php';

try {
    $pdo = pdo_connect();

    $stmt = $pdo->query("
        SELECT id, offer_id, offer_name, offer_type, offer_url, status, created_at
        FROM offers
        ORDER BY id ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'id' => (int) $row['id'],
            'offer_id' => $row['offer_id'],
            'offer_name' => $row['offer_name'],
            'offer_type' => $row['offer_type'],
            'offer_url' => $row['offer_url'],
            'status' => (int) $row['status'],
            'created_at' => $row['created_at'],
        ];
    }

    echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("get_offers error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'data' => []], JSON_UNESCAPED_UNICODE);
}
