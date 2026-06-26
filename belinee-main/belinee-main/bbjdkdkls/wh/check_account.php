<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/database/pdo_connect.php';

$accountId = isset($_GET['account_id']) ? (int) $_GET['account_id'] : 0;
if ($accountId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid account_id']);
    exit;
}

try {
    $pdo = pdo_connect();

    $stmt = $pdo->prepare("SELECT id, status FROM accounts WHERE id = ? AND status > 0 LIMIT 1");
    $stmt->execute([$accountId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        echo json_encode(['success' => false, 'error' => 'Account not found']);
        exit;
    }

    do {
        $actionId = random_int(1, 9999999);
        $stmt = $pdo->prepare("SELECT 1 FROM actions WHERE action_id = ? LIMIT 1");
        $stmt->execute([$actionId]);
    } while ($stmt->fetch());

    $stmt = $pdo->prepare("INSERT INTO actions (action_id, account_id, ended, action_response) VALUES (?, ?, 0, NULL)");
    $stmt->execute([$actionId, $accountId]);

    echo json_encode(['success' => 'ok', 'action_id' => $actionId], JSON_UNESCAPED_UNICODE);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    $baseDir = dirname(__DIR__);
    $script = '/opt/scripts/test/invader/check_account.js';
    if (file_exists($script)) {
        $cmd = sprintf('node %s %d %d > /dev/null 2>&1 &', escapeshellarg($script), $accountId, $actionId);
        exec($cmd);
    } else {
        $response = ($account['status'] ?? 0) === 2 ? 'active' : 'deactive';
        $stmt = $pdo->prepare("UPDATE actions SET ended = 1, action_response = ? WHERE action_id = ?");
        $stmt->execute([$response, $actionId]);
        $stmt = $pdo->prepare("SELECT account_data FROM accounts WHERE id = ? LIMIT 1");
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $ad = [];
        if ($row && !empty($row['account_data'])) {
            $decoded = json_decode($row['account_data'], true);
            if (is_array($decoded)) $ad = $decoded;
        }
        $ad['lastAliveCheck'] = time();
        $ad['lastAliveResult'] = $response;
        $stmt = $pdo->prepare("UPDATE accounts SET account_data = ? WHERE id = ?");
        $stmt->execute([json_encode($ad, JSON_UNESCAPED_UNICODE), $accountId]);
    }

} catch (Exception $e) {
    error_log("wh/check_account error: " . $e->getMessage());
    if (!headers_sent()) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}
