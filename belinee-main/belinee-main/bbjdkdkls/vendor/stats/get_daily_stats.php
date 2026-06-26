<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../database/pdo_connect.php';

$statusLabels = [
    1 => 'в обработке',
    2 => 'добавлено успешно',
];

try {
    $pdo = pdo_connect();

    $stmt = $pdo->query("
        SELECT a.id, a.offer_id, a.phone, a.auth_data, a.account_data, a.status, a.created_at,
               o.offer_id AS offer_code, o.offer_name
        FROM accounts a
        LEFT JOIN offers o ON a.offer_id = o.id
        ORDER BY a.created_at DESC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($rows as $row) {
        $accountData = ['chats' => 0, 'contacts' => 0];
        if (!empty($row['account_data'])) {
            $decoded = json_decode($row['account_data'], true);
            if (is_array($decoded)) {
                $accountData = array_merge($accountData, $decoded);
            }
        }
        $result[] = [
            'id' => (int) $row['id'],
            'offer_id' => $row['offer_id'] ? (int) $row['offer_id'] : null,
            'offer_code' => $row['offer_code'] ?? null,
            'offer_name' => $row['offer_name'] ?? '—',
            'phone' => $row['phone'],
            'auth_data' => $row['auth_data'],
            'account_data' => $accountData,
            'status' => (int) $row['status'],
            'status_label' => $statusLabels[(int) $row['status']] ?? 'неизвестно',
            'created_at' => $row['created_at'],
        ];
    }

    echo json_encode(['success' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("get_daily_stats error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
