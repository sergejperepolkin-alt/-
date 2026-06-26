<?php
require_once __DIR__ . '/vendor/database/pdo_connect.php';

session_start();

$isLoggedIn = false;
$user = null;

// Проверяем авторизацию по токену из куки
if (isset($_COOKIE['auth_token'])) {
    try {
        $pdo = pdo_connect();
        $token = $_COOKIE['auth_token'];
        
        $stmt = $pdo->prepare("
            SELECT u.id, u.login, u.email, u.status, u.created_at, u.maxmarket_data
            FROM users u
            INNER JOIN users_sessions s ON u.id = s.user_id
            WHERE s.token = ? 
            AND s.status = 1 
            AND u.status = 1
            AND (s.expires_at IS NULL OR s.expires_at > NOW())
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if ($user) {
            $isLoggedIn = true;
        }
    } catch (Exception $e) {
        error_log("Auth check error: " . $e->getMessage());
    }
}

// Если не авторизован - редирект на главную
if (!$isLoggedIn) {
    header('Location: /index.php');
    exit;
}

// Soft-delete аккаунта (status = 0 — скрыт из списка, не удалён из БД)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['account_delete_id'])) {
    $delId = (int) $_POST['account_delete_id'];
    if ($delId > 0) {
        try {
            $pdo = pdo_connect();
            $stmt = $pdo->prepare("UPDATE accounts SET status = 0 WHERE id = ?");
            $stmt->execute([$delId]);
        } catch (Exception $e) {
            error_log("Account soft-delete error: " . $e->getMessage());
        }
    }
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/dashboard.php'));
    exit;
}

// Статистика и последние аккаунты из БД
$statsAttempts = 0;
$statsSuccess = 0;
$statsPending = 0;
$statsOffers = 0;
$lastAccounts = [];

try {
    $pdo = pdo_connect();
    // Агрегаты по таблице accounts
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS success,
            SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS pending
        FROM accounts
    ");
    $row = $stmt->fetch();
    if ($row) {
        $statsAttempts = (int) $row['total'];
        $statsSuccess = (int) $row['success'];
        $statsPending = (int) $row['pending'];
        $statsOffers = (int) $row['pending'];
    }
    // Последние 10 аккаунтов (status > 0 — исключаем мягко удалённые)
    $stmt = $pdo->query("
        SELECT a.id, a.phone, a.account_data, a.status, a.created_at,
               o.offer_name
        FROM accounts a
        LEFT JOIN offers o ON a.offer_id = o.id
        WHERE a.status > 0
        ORDER BY a.created_at DESC
        LIMIT 10
    ");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ad = ['chats' => 0, 'contacts' => 0];
        if (!empty($r['account_data'])) {
            $decoded = json_decode($r['account_data'], true);
            if (is_array($decoded)) {
                $ad = array_merge($ad, $decoded);
            }
        }
        $lastAccounts[] = [
            'id' => $r['id'],
            'offer_name' => $r['offer_name'] ?: '—',
            'phone' => $r['phone'],
            'chats' => (int) $ad['chats'],
            'contacts' => (int) $ad['contacts'],
            'status' => (int) $r['status'],
            'created_at' => $r['created_at'],
        ];
    }
    // Все аккаунты для раздела Аккаунты (status > 0 — исключаем мягко удалённые)
    $allAccounts = [];
    $stmt = $pdo->query("
        SELECT a.id, a.phone, a.auth_data, a.account_data, a.offer_id,
            COALESCE(a.auth_status, 0) AS auth_status,
            JSON_UNQUOTE(JSON_EXTRACT(a.auth_data, '$.errorMessageText')) AS error_message,
            a.status, a.created_at,
            o.offer_name,
            COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.account_data, '$.country')),
                CASE
                    WHEN a.phone LIKE '+7%' OR a.phone LIKE '7%' THEN 'RU'
                    WHEN a.phone LIKE '+380%' OR a.phone LIKE '380%' THEN 'UA'
                    WHEN a.phone LIKE '+375%' OR a.phone LIKE '375%' THEN 'BY'
                    WHEN a.phone LIKE '+77%' THEN 'KZ'
                    ELSE 'Other'
                END
            ) AS country
        FROM accounts a
        LEFT JOIN offers o ON a.offer_id = o.id
        WHERE a.status > 0
        ORDER BY a.created_at DESC
        LIMIT 5000
    ");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ad = ['chats' => 0, 'contacts' => 0];
        if (!empty($r['account_data'])) {
            $decoded = json_decode($r['account_data'], true);
            if (is_array($decoded)) {
                $ad = array_merge($ad, $decoded);
            }
        }
        $authData = [];
        if (!empty($r['auth_data'])) {
            $authDecoded = json_decode($r['auth_data'], true);
            if (is_array($authDecoded)) $authData = $authDecoded;
        }
        $errMsg = trim($r['error_message'] ?? '') ?: ($authData['errorMessageText'] ?? '');
        $allAccounts[] = [
            'id' => $r['id'],
            'phone' => $r['phone'],
            'name' => trim($ad['name'] ?? '') ?: null,
            'chats' => (int) $ad['chats'],
            'contacts' => (int) $ad['contacts'],
            'lastAliveCheck' => isset($ad['lastAliveCheck']) ? (int) $ad['lastAliveCheck'] : null,
            'status' => (int) $r['status'],
            'auth_status' => (int) ($r['auth_status'] ?? 0),
            'error_message' => $errMsg ?: null,
            'auth_data' => $r['auth_data'],
            'created_at' => $r['created_at'],
            'country' => trim($r['country'] ?? '') ?: 'Other',
            'offer_id' => !empty($r['offer_id']) ? (int) $r['offer_id'] : null,
            'offer_name' => trim($r['offer_name'] ?? '') ?: null,
        ];
    }
} catch (Exception $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    // Демо-значения, если таблицы нет
    $statsAttempts = 1847;
    $statsSuccess = 52;
    $statsPending = 340;
    $statsOffers = 18;
    $lastAccounts = [
        ['phone' => '+7 999 123-45-67', 'offer_name' => 'Макс ловля #1', 'chats' => 48, 'contacts' => 2, 'status' => 2, 'created_at' => '2025-02-14 12:34:00'],
        ['phone' => '+7 999 234-56-78', 'offer_name' => 'Макс ловля #1', 'chats' => 48, 'contacts' => 2, 'status' => 2, 'created_at' => '2025-02-14 11:22:00'],
        ['phone' => '+7 999 345-67-89', 'offer_name' => 'Оффер демо', 'chats' => 48, 'contacts' => 2, 'status' => 1, 'created_at' => '2025-02-14 10:15:00'],
    ];
    $allAccounts = [];
    foreach (array_merge($lastAccounts, [['phone' => '+7 999 000-00-00', 'chats' => 0, 'contacts' => 0, 'status' => 3, 'created_at' => '2025-02-14 09:00:00']]) as $a) {
        $a['country'] = $a['country'] ?? 'RU';
        $a['chats'] = $a['chats'] ?? 0;
        $a['contacts'] = $a['contacts'] ?? 0;
        $allAccounts[] = $a;
    }
}
$accountsSort = isset($_GET['accounts_sort']) && in_array($_GET['accounts_sort'], ['chats', 'date', 'geo'], true) ? $_GET['accounts_sort'] : 'date';
$accountsOrder = isset($_GET['accounts_order']) && $_GET['accounts_order'] === 'asc' ? 'asc' : 'desc';
$accountsFilterStatus = isset($_GET['accounts_filter_status']) && in_array($_GET['accounts_filter_status'], ['active', 'in_work', 'errors'], true) ? $_GET['accounts_filter_status'] : 'active';
// Фильтр по времени для раздела "Аккаунты" отключён намеренно.
$accountsFilterDate = 'all';
$accountsFilterGeo = isset($_GET['accounts_filter_geo']) ? trim($_GET['accounts_filter_geo']) : '';
$accountsFilterChats = isset($_GET['accounts_filter_chats']) ? (int)$_GET['accounts_filter_chats'] : 0;
$statusFilterMap = ['active' => 2, 'in_work' => 1, 'errors' => 3];
$accountsPerPage = 10;
$accountsTotal = 0;
$accountsPages = 1;
$accountsPage = 1;
$accountsOffset = 0;
$accountsOnPage = [];

// Раздел "Аккаунты": фильтрация/сортировка/пагинация делаются в SQL,
// иначе LIMIT на выборке "съедает" активные аккаунты, если они не в последних N строках.
try {
    $pdo = pdo_connect();

    $statusWanted = (int) ($statusFilterMap[$accountsFilterStatus] ?? 2);
    $filters = ["a.status > 0", "a.status = :statusWanted"];
    $params = [':statusWanted' => $statusWanted];

    $chatsExpr = "COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(a.account_data, '$.chats')) AS UNSIGNED), 0)";
    $contactsExpr = "COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(a.account_data, '$.contacts')) AS UNSIGNED), 0)";
    $nameExpr = "NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(a.account_data, '$.name'))), '')";
    $countryExpr = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(a.account_data, '$.country')),
        CASE
            WHEN a.phone LIKE '+7%' OR a.phone LIKE '7%' THEN 'RU'
            WHEN a.phone LIKE '+380%' OR a.phone LIKE '380%' THEN 'UA'
            WHEN a.phone LIKE '+375%' OR a.phone LIKE '375%' THEN 'BY'
            WHEN a.phone LIKE '+77%' THEN 'KZ'
            ELSE 'Other'
        END
    )";

    if ($accountsFilterGeo !== '') {
        $filters[] = "{$countryExpr} = :geo";
        $params[':geo'] = $accountsFilterGeo;
    }
    if ($accountsFilterChats > 0) {
        $filters[] = "{$chatsExpr} >= :minChats";
        $params[':minChats'] = $accountsFilterChats;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $filters);

    $orderSql = 'a.created_at ' . strtoupper($accountsOrder);
    if ($accountsSort === 'chats') {
        $orderSql = "{$chatsExpr} " . strtoupper($accountsOrder) . ", a.created_at DESC";
    } elseif ($accountsSort === 'geo') {
        $orderSql = "{$countryExpr} " . strtoupper($accountsOrder) . ", a.created_at DESC";
    }

    // COUNT для пагинации
    $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM accounts a {$whereSql}");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $accountsTotal = (int)($row['cnt'] ?? 0);
    $accountsPages = max(1, (int)ceil($accountsTotal / $accountsPerPage));

    $accountsPage = isset($_GET['accounts_page']) ? (int)$_GET['accounts_page'] : 1;
    $accountsPage = max(1, min($accountsPage, $accountsPages));
    $accountsOffset = ($accountsPage - 1) * $accountsPerPage;

    // Страница данных
    $stmt = $pdo->prepare("
        SELECT
            a.id, a.phone, a.auth_data, a.account_data, a.offer_id,
            COALESCE(a.auth_status, 0) AS auth_status,
            JSON_UNQUOTE(JSON_EXTRACT(a.auth_data, '$.errorMessageText')) AS error_message,
            a.status, a.created_at,
            o.offer_name,
            {$countryExpr} AS country,
            {$chatsExpr} AS chats_calc,
            {$contactsExpr} AS contacts_calc,
            {$nameExpr} AS name_calc
        FROM accounts a
        LEFT JOIN offers o ON a.offer_id = o.id
        {$whereSql}
        ORDER BY {$orderSql}
        LIMIT :limitRows OFFSET :offsetRows
    ");
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limitRows', $accountsPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':offsetRows', $accountsOffset, PDO::PARAM_INT);
    $stmt->execute();

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $authData = [];
        if (!empty($r['auth_data'])) {
            $authDecoded = json_decode($r['auth_data'], true);
            if (is_array($authDecoded)) $authData = $authDecoded;
        }
        $errMsg = trim($r['error_message'] ?? '') ?: ($authData['errorMessageText'] ?? '');
        $ad = ['chats' => 0, 'contacts' => 0];
        if (!empty($r['account_data'])) {
            $decoded = json_decode($r['account_data'], true);
            if (is_array($decoded)) $ad = array_merge($ad, $decoded);
        }

        $accountsOnPage[] = [
            'id' => $r['id'],
            'phone' => $r['phone'],
            'name' => isset($r['name_calc']) && $r['name_calc'] !== '' ? $r['name_calc'] : null,
            'chats' => (int)($r['chats_calc'] ?? 0),
            'contacts' => (int)($r['contacts_calc'] ?? 0),
            'lastAliveCheck' => isset($ad['lastAliveCheck']) ? (int) $ad['lastAliveCheck'] : null,
            'status' => (int)($r['status'] ?? 0),
            'auth_status' => (int)($r['auth_status'] ?? 0),
            'error_message' => $errMsg ?: null,
            'auth_data' => $r['auth_data'],
            'created_at' => $r['created_at'],
            'country' => trim($r['country'] ?? '') ?: 'Other',
            'offer_id' => !empty($r['offer_id']) ? (int)$r['offer_id'] : null,
            'offer_name' => trim($r['offer_name'] ?? '') ?: null,
            'account_data' => $r['account_data'],
        ];
    }
} catch (Exception $e) {
    // Если что-то пойдёт не так (например, старая версия MySQL без JSON_*),
    // то хотя бы не "убиваем" страницу — оставляем старую логику на $allAccounts.
    error_log("Accounts SQL paging error: " . $e->getMessage());
    $allAccounts = array_filter($allAccounts, function($a) use ($accountsFilterStatus, $statusFilterMap, $accountsFilterDate, $accountsFilterGeo, $accountsFilterChats) {
        if (($a['status'] ?? 0) !== ($statusFilterMap[$accountsFilterStatus] ?? 2)) return false;
        if ($accountsFilterDate !== 'all') {
            $ts = strtotime($a['created_at'] ?? 0);
            $cutoff = $accountsFilterDate === 'today' ? strtotime('today') : ($accountsFilterDate === '7' ? strtotime('-7 days') : strtotime('-30 days'));
            if ($ts < $cutoff) return false;
        }
        if ($accountsFilterGeo !== '' && ($a['country'] ?? '') !== $accountsFilterGeo) return false;
        if ($accountsFilterChats > 0 && ($a['chats'] ?? 0) < $accountsFilterChats) return false;
        return true;
    });
    $allAccounts = array_values($allAccounts);
    usort($allAccounts, function($a, $b) use ($accountsSort, $accountsOrder) {
        $mul = $accountsOrder === 'asc' ? 1 : -1;
        if ($accountsSort === 'chats') return $mul * (($a['chats'] ?? 0) - ($b['chats'] ?? 0));
        if ($accountsSort === 'geo') return $mul * strcmp($a['country'] ?? '', $b['country'] ?? '');
        return $mul * (strtotime($a['created_at'] ?? 0) - strtotime($b['created_at'] ?? 0));
    });
    $accountsTotal = count($allAccounts);
    $accountsPages = max(1, (int)ceil($accountsTotal / $accountsPerPage));
    $accountsPage = isset($_GET['accounts_page']) ? max(1, min((int)$_GET['accounts_page'], $accountsPages)) : 1;
    $accountsOffset = ($accountsPage - 1) * $accountsPerPage;
    $accountsOnPage = array_slice($allAccounts, $accountsOffset, $accountsPerPage);
}

$statsFail = max(0, $statsAttempts - $statsSuccess - $statsPending);
$showAccounts = isset($_GET['accounts_sort']) || isset($_GET['accounts_order']) || isset($_GET['accounts_filter_status']) || isset($_GET['accounts_filter_date']) || isset($_GET['accounts_filter_geo']) || isset($_GET['accounts_filter_chats']) || isset($_GET['accounts_page']);
$showSettings = isset($_GET['view']) && $_GET['view'] === 'settings';

// Период для раздела Статистика (today|7|30|all)
$statsPeriod = isset($_GET['stats_period']) && in_array($_GET['stats_period'], ['today', '7', '30', 'all'], true)
    ? $_GET['stats_period']
    : 'today';
$statsDateWhere = '';
$statsPeriodDays = 1;
switch ($statsPeriod) {
    case 'today':
        $statsDateWhere = " created_at >= CURDATE() ";
        $statsPeriodDays = 1;
        break;
    case '7':
        $statsDateWhere = " created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) ";
        $statsPeriodDays = 7;
        break;
    case '30':
        $statsDateWhere = " created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) ";
        $statsPeriodDays = 30;
        break;
    case 'all':
        $statsDateWhere = '';
        $statsPeriodDays = 365;
        break;
}

// Статистика за период (для раздела Статистика)
$statsAttemptsPeriod = 0;
$statsSuccessPeriod = 0;
$statsFailPeriod = 0;
try {
    $pdo = pdo_connect();
    $where = $statsDateWhere ? " WHERE {$statsDateWhere} " : '';
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS success,
            SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS pending
        FROM accounts
        {$where}
    ");
    $row = $stmt->fetch();
    if ($row) {
        $statsAttemptsPeriod = (int) $row['total'];
        $statsSuccessPeriod = (int) $row['success'];
        $statsFailPeriod = max(0, $statsAttemptsPeriod - $statsSuccessPeriod - (int) $row['pending']);
    }
} catch (Exception $e) {
    $statsAttemptsPeriod = $statsAttempts;
    $statsSuccessPeriod = $statsSuccess;
    $statsFailPeriod = $statsFail;
}

// Офферов в работе — кол-во разных offer_id в аккаунтах за период
$statsOffersInWork = 0;
try {
    $pdo = pdo_connect();
    $where = $statsDateWhere ? " WHERE {$statsDateWhere} AND offer_id IS NOT NULL " : ' WHERE offer_id IS NOT NULL ';
    $stmt = $pdo->query("SELECT COUNT(DISTINCT offer_id) AS cnt FROM accounts {$where}");
    $row = $stmt->fetch();
    if ($row) {
        $statsOffersInWork = (int) $row['cnt'];
    }
} catch (Exception $e) {
    $statsOffersInWork = 0;
}

$statusLabels = [1 => 'В обработке', 2 => 'Успешно', 3 => 'Ошибка'];
$statusClasses = [1 => 'status--pending', 2 => 'status--success', 3 => 'status--fail'];

// График офферов и детальная статистика по офферам
$offerChartLabels = [];
$offerChartData = [];
$offerStats = [];
$offerChartColors = ['#22d3ee', '#4ade80', '#fb923c', '#d4af37', '#8b7355'];
try {
    $pdo = pdo_connect();
    $stmt = $pdo->query("
        SELECT o.offer_name,
            COUNT(a.id) AS cnt,
            SUM(CASE WHEN a.status = 2 THEN 1 ELSE 0 END) AS success,
            SUM(CASE WHEN a.status = 1 THEN 1 ELSE 0 END) AS pending
        FROM offers o
        LEFT JOIN accounts a ON a.offer_id = o.id
        GROUP BY o.id, o.offer_name
        ORDER BY cnt DESC
    ");
    $idx = 0;
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $offerChartLabels[] = $r['offer_name'];
        $offerChartData[] = (int) $r['cnt'];
        $offerStats[] = [
            'total' => (int) $r['cnt'],
            'success' => (int) $r['success'],
            'pending' => (int) $r['pending'],
        ];
        $idx++;
    }
} catch (Exception $e) {
    $offerChartLabels = ['Макс ловля #1', 'Макс ловля #2', 'Оффер демо'];
    $offerChartData = [2, 1, 1];
    $offerStats = [['total'=>2,'success'=>1,'pending'=>1], ['total'=>1,'success'=>0,'pending'=>1], ['total'=>1,'success'=>0,'pending'=>1]];
}
if (empty($offerChartData)) {
    $offerChartLabels = ['Нет данных'];
    $offerChartData = [1];
    $offerStats = [['total'=>1,'success'=>0,'pending'=>0]];
}
$offerChartColors = array_slice($offerChartColors, 0, count($offerChartLabels));

// Распределение по гео (country из account_data JSON или по префиксу телефона)
$geoChartLabels = [];
$geoStats = [];
try {
    $pdo = pdo_connect();
    $stmt = $pdo->query("
        SELECT 
            COALESCE(JSON_UNQUOTE(JSON_EXTRACT(account_data, '$.country')), 
                CASE 
                    WHEN phone LIKE '+7%' OR phone LIKE '7%' THEN 'RU'
                    WHEN phone LIKE '+380%' OR phone LIKE '380%' THEN 'UA'
                    WHEN phone LIKE '+375%' OR phone LIKE '375%' THEN 'BY'
                    WHEN phone LIKE '+77%' THEN 'KZ'
                    ELSE 'Other'
                END
            ) AS country,
            COUNT(*) AS total,
            SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS success,
            SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS pending
        FROM accounts
        GROUP BY country
        ORDER BY total DESC
        LIMIT 20
    ");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $country = trim($r['country']) ?: 'Other';
        $geoChartLabels[] = $country;
        $geoStats[] = [
            'total' => (int)$r['total'],
            'success' => (int)$r['success'],
            'pending' => (int)$r['pending'],
        ];
    }
} catch (Exception $e) {
    $geoChartLabels = ['RU', 'UA', 'KZ', 'BY', 'Other'];
    $geoStats = [['total'=>45,'success'=>12,'pending'=>8], ['total'=>28,'success'=>7,'pending'=>5], ['total'=>12,'success'=>3,'pending'=>2], ['total'=>8,'success'=>2,'pending'=>1], ['total'=>15,'success'=>4,'pending'=>3]];
}
if (empty($geoStats)) {
    $geoChartLabels = ['RU', 'UA', 'KZ'];
    $geoStats = [['total'=>1,'success'=>0,'pending'=>0], ['total'=>1,'success'=>0,'pending'=>0], ['total'=>1,'success'=>0,'pending'=>0]];
}
$geoTotal = array_sum(array_column($geoStats, 'total'));

// График «Авторизации за сутки»: по часам за сегодня (или последние 24 ч)
$authChartLabels = [];
$authChartAttempts = [];
$authChartSuccess = [];
for ($h = 0; $h < 24; $h++) {
    $authChartLabels[] = sprintf('%02d:00', $h);
    $authChartAttempts[] = 0;
    $authChartSuccess[] = 0;
}
try {
    $pdo = pdo_connect();
    $stmt = $pdo->query("
        SELECT HOUR(created_at) AS h, COUNT(*) AS attempts, SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS success
        FROM accounts
        WHERE created_at >= CURDATE()
        GROUP BY HOUR(created_at)
    ");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $hour = (int) $r['h'];
        if ($hour >= 0 && $hour < 24) {
            $authChartAttempts[$hour] = (int) $r['attempts'];
            $authChartSuccess[$hour] = (int) $r['success'];
        }
    }
} catch (Exception $e) {
    // Демо-данные при ошибке или пустой БД
    $authChartAttempts = [45, 32, 28, 52, 120, 185, 210, 195, 168, 142, 98, 72, 65, 55, 48, 70, 95, 110, 88, 75, 60, 50, 42, 38];
    $authChartSuccess = [8, 5, 4, 12, 28, 42, 48, 45, 38, 32, 22, 15, 12, 10, 8, 18, 25, 30, 22, 18, 14, 12, 8, 7];
}
$authChartFail = [];
for ($h = 0; $h < 24; $h++) {
    $authChartFail[] = max(0, ($authChartAttempts[$h] ?? 0) - ($authChartSuccess[$h] ?? 0));
}

// Аккаунты за период для графика в Статистике (зависит от stats_period)
// При "Сегодня" — по часам, иначе по дням
$periodChartLabels = [];
$periodChartTotal = [];
$periodChartSuccess = [];
$periodChartFail = [];
$periodDays = $statsPeriodDays;
try {
    $pdo = pdo_connect();
    if ($statsPeriod === 'today') {
        // По часам за сутки
        $stmt = $pdo->query("
            SELECT HOUR(created_at) AS h,
                COUNT(*) AS total,
                SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS success,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS pending
            FROM accounts
            WHERE created_at >= CURDATE()
            GROUP BY HOUR(created_at)
        ");
        $dataByHour = array_fill(0, 24, ['total'=>0,'success'=>0,'fail'=>0]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $h = (int) $r['h'];
            if ($h >= 0 && $h < 24) {
                $pending = (int) $r['pending'];
                $success = (int) $r['success'];
                $total = (int) $r['total'];
                $dataByHour[$h] = [
                    'total' => $total,
                    'success' => $success,
                    'fail' => max(0, $total - $success - $pending),
                ];
            }
        }
        for ($h = 0; $h < 24; $h++) {
            $periodChartLabels[] = sprintf('%02d:00', $h);
            $row = $dataByHour[$h];
            $periodChartTotal[] = $row['total'];
            $periodChartSuccess[] = $row['success'];
            $periodChartFail[] = $row['fail'];
        }
    } else {
        // По дням
        $intervalDays = ($statsPeriod === 'all') ? 365 : (int) $periodDays;
        $stmt = $pdo->query("
            SELECT DATE(created_at) AS d,
                COUNT(*) AS total,
                SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS success,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS pending
            FROM accounts
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL " . (int)$intervalDays . " DAY)
            GROUP BY DATE(created_at)
            ORDER BY d
        ");
        $dataByDate = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pending = (int) $r['pending'];
            $success = (int) $r['success'];
            $total = (int) $r['total'];
            $dataByDate[$r['d']] = [
                'total' => $total,
                'success' => $success,
                'fail' => max(0, $total - $success - $pending),
            ];
        }
        for ($i = $intervalDays - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $periodChartLabels[] = date('d.m', strtotime($d));
            $row = $dataByDate[$d] ?? ['total'=>0,'success'=>0,'fail'=>0];
            $periodChartTotal[] = $row['total'];
            $periodChartSuccess[] = $row['success'];
            $periodChartFail[] = $row['fail'];
        }
    }
} catch (Exception $e) {
    if ($statsPeriod === 'today') {
        for ($h = 0; $h < 24; $h++) {
            $periodChartLabels[] = sprintf('%02d:00', $h);
            $periodChartTotal[] = 0;
            $periodChartSuccess[] = 0;
            $periodChartFail[] = 0;
        }
    } else {
        for ($i = min($periodDays, 30) - 1; $i >= 0; $i--) {
            $periodChartLabels[] = date('d.m', strtotime("-$i days"));
            $periodChartTotal[] = 0;
            $periodChartSuccess[] = 0;
            $periodChartFail[] = 0;
        }
    }
}

// Офферы и гео за период (для раздела Статистика)
$statsOfferLabels = [];
$statsOfferStats = [];
$statsOfferTotal = 0;
$statsGeoLabels = [];
$statsGeoStats = [];
$statsGeoTotal = 0;
$accDateCond = $statsDateWhere ? ' AND a.' . trim($statsDateWhere) : '';
try {
    $pdo = pdo_connect();
    $stmt = $pdo->query("
        SELECT o.offer_name,
            COUNT(a.id) AS cnt,
            SUM(CASE WHEN a.status = 2 THEN 1 ELSE 0 END) AS success,
            SUM(CASE WHEN a.status = 1 THEN 1 ELSE 0 END) AS pending
        FROM offers o
        LEFT JOIN accounts a ON a.offer_id = o.id " . ($accDateCond ? $accDateCond : '') . "
        GROUP BY o.id, o.offer_name
        ORDER BY cnt DESC
    ");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $statsOfferLabels[] = $r['offer_name'];
        $statsOfferStats[] = [
            'total' => (int) $r['cnt'],
            'success' => (int) $r['success'],
            'pending' => (int) $r['pending'],
        ];
    }
    $statsOfferTotal = array_sum(array_column($statsOfferStats, 'total'));
    if (empty($statsOfferLabels)) {
        $statsOfferLabels = ['Нет данных'];
        $statsOfferStats = [['total'=>0,'success'=>0,'pending'=>0]];
    }
} catch (Exception $e) {
    $statsOfferLabels = $offerChartLabels;
    $statsOfferStats = $offerStats;
    $statsOfferTotal = array_sum(array_column($offerStats, 'total'));
}
try {
    $pdo = pdo_connect();
    $geoWhere = $statsDateWhere ? " WHERE {$statsDateWhere} " : '';
    $stmt = $pdo->query("
        SELECT 
            COALESCE(JSON_UNQUOTE(JSON_EXTRACT(account_data, '$.country')), 
                CASE 
                    WHEN phone LIKE '+7%' OR phone LIKE '7%' THEN 'RU'
                    WHEN phone LIKE '+380%' OR phone LIKE '380%' THEN 'UA'
                    WHEN phone LIKE '+375%' OR phone LIKE '375%' THEN 'BY'
                    WHEN phone LIKE '+77%' THEN 'KZ'
                    ELSE 'Other'
                END
            ) AS country,
            COUNT(*) AS total,
            SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) AS success,
            SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS pending
        FROM accounts
        {$geoWhere}
        GROUP BY country
        ORDER BY total DESC
        LIMIT 20
    ");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $country = trim($r['country']) ?: 'Other';
        $statsGeoLabels[] = $country;
        $statsGeoStats[] = [
            'total' => (int)$r['total'],
            'success' => (int)$r['success'],
            'pending' => (int)$r['pending'],
        ];
    }
    $statsGeoTotal = array_sum(array_column($statsGeoStats, 'total'));
    if (empty($statsGeoStats)) {
        $statsGeoLabels = ['Нет данных'];
        $statsGeoStats = [['total'=>0,'success'=>0,'pending'=>0]];
    }
} catch (Exception $e) {
    $statsGeoLabels = $geoChartLabels;
    $statsGeoStats = $geoStats;
    $statsGeoTotal = $geoTotal;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - INVADER PANEL</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #0d0c09;
            min-height: 100vh;
            color: #ffffff;
        }

        .header {
            padding: 16px 40px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
        }

        .logo {
            color: #ffffff;
            font-size: 24px;
            font-family: 'Montserrat', sans-serif;
            font-weight: 700;
            text-decoration: none;
            letter-spacing: 1px;
            transition: all 0.3s ease;
        }

        .logo:hover {
            color: #d4af37;
            transform: translateX(2px);
        }

        .nav-menu {
            display: flex;
            align-items: center;
        }

        .nav-menu__block {
            display: flex;
            align-items: center;
            gap: 4px;
            background: rgba(39, 39, 47, 0.5);
            border: 1px solid #27272f;
            border-radius: 10px;
            padding: 6px 8px;
            transition: all 0.3s ease;
        }

        .nav-menu__block:hover {
            background: rgba(39, 39, 47, 0.8);
            border-color: #3d3520;
        }

        .nav-button {
            padding: 10px 16px;
            color: #94a3b8;
            font-weight: 600;
            font-size: 17px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 8px;
        }

        .nav-button:hover {
            color: #e2e8f0;
            background: rgba(39, 39, 47, 0.5);
        }

        .nav-button.active {
            color: #d4af37;
            background: rgba(39, 39, 47, 0.6);
        }

        .nav-button svg {
            width: 22px;
            height: 22px;
            flex-shrink: 0;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .user-menu {
            position: relative;
        }

        .user-menu-trigger {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(39, 39, 47, 0.5);
            border: 1px solid #27272f;
            border-radius: 10px;
            padding: 10px 18px;
            color: #94a3b8;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .user-menu-trigger:hover {
            background: rgba(39, 39, 47, 0.8);
            border-color: #3d3520;
            color: #e2e8f0;
        }

        .user-menu-trigger svg {
            width: 16px;
            height: 16px;
            fill: currentColor;
            transition: transform 0.3s ease;
        }

        .user-menu.open .user-menu-trigger svg {
            transform: rotate(180deg);
        }

        .user-menu-dropdown {
            position: absolute;
            right: 0;
            top: calc(100% + 12px);
            min-width: 200px;
            background: #1a1812;
            border: 1px solid #27272f;
            border-radius: 12px;
            padding: 8px;
            display: none;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
            overflow: hidden;
        }

        .user-menu.open .user-menu-dropdown {
            display: block;
        }

        .user-menu-item {
            display: block;
            padding: 12px 16px;
            border-radius: 8px;
            color: #94a3b8;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .user-menu-item:hover {
            background: rgba(220, 38, 38, 0.15);
            color: #fca5a5;
        }

        .content {
            padding: 16px 40px 48px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .dashboard-title {
            font-size: 18px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 28px;
        }

        .dashboard-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
        }

        .dashboard-header .dashboard-title {
            margin-bottom: 0;
        }

        .period-select {
            display: flex;
            gap: 4px;
            background: #141210;
            border: 1px solid #27272f;
            border-radius: 8px;
            padding: 4px;
        }

        .period-select__btn {
            display: inline-block;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            color: #94a3b8;
            background: transparent;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .period-select__btn:hover {
            color: #e2e8f0;
            background: rgba(39, 39, 47, 0.5);
        }

        .period-select__btn.active {
            color: #d4af37;
            background: rgba(39, 39, 47, 0.6);
        }

        @media (max-width: 768px) {
            .dashboard-title {
                text-align: center;
            }
        }
        @media (max-width: 600px) {
            .dashboard-header {
                flex-direction: column;
                align-items: center;
                gap: 16px;
            }
            .dashboard-header .dashboard-title {
                text-align: center;
            }
            .period-select { flex-wrap: wrap; }
        }

        .stats-strip-wrap {
            margin-bottom: 36px;
        }

        .stats-strip {
            display: flex;
            flex-wrap: wrap;
            gap: 0;
            background: #141210;
            border-radius: 6px;
            padding: 0 4px;
            border: 1px solid #27272f;
        }

        .stat-item {
            flex: 1;
            min-width: 140px;
            padding: 20px 24px;
            border-right: 1px solid #27272f;
            text-align: center;
        }

        .stat-item:last-child { border-right: none; }

        .stat-item__value {
            font-size: 26px;
            font-weight: 700;
            color: #f1f5f9;
            font-variant-numeric: tabular-nums;
            letter-spacing: -0.02em;
        }

        .stat-item__label {
            font-size: 12px;
            color: #64748b;
            margin-top: 4px;
        }

        .stat-item__badge {
            display: inline-block;
            font-size: 11px;
            margin-top: 6px;
            padding: 2px 6px;
            border-radius: 4px;
        }

        .stat-item__badge.up { background: rgba(34, 197, 94, 0.2); color: #4ade80; }
        .stat-item__badge.down { background: rgba(239, 68, 68, 0.2); color: #f87171; }

        .stat-item__value--cyan { color: #22d3ee; }
        .stat-item__value--lime { color: #4ade80; }
        .stat-item__value--orange { color: #fb923c; }
        .stat-item__value--red { color: #f87171; }

        .charts-section {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 24px;
            margin-bottom: 24px;
        }

        .panel {
            background: #141210;
            border: 1px solid #27272f;
            border-radius: 6px;
            padding: 24px;
        }

        .panel--mb {
            margin-bottom: 36px;
        }

        .sales-panel {
            position: relative;
            overflow: hidden;
        }
        .sales-panel--blur .panel__head,
        .sales-panel--blur .stats-strip {
            filter: blur(6px);
            pointer-events: none;
            user-select: none;
        }
        .sales-panel__overlay {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(15, 15, 35, 0.7);
        }
        .sales-panel__cta-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
            padding: 0 20px;
        }
        .sales-panel__cta {
            font-size: 16px;
            font-weight: 600;
            color: #94a3b8;
            text-align: center;
            max-width: 380px;
            line-height: 1.5;
            margin: 0;
        }
        .sales-panel__btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            font-size: 14px;
            font-weight: 600;
            color: #d4af37;
            background: rgba(99, 102, 241, 0.15);
            border: 1px solid rgba(99, 102, 241, 0.4);
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .sales-panel__btn:hover {
            background: rgba(99, 102, 241, 0.25);
            color: #d4af37;
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px 32px;
        }
        .metrics-grid__item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .metrics-grid__val {
            font-size: 24px;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }
        .metrics-grid__lbl {
            font-size: 12px;
            color: #64748b;
        }
        @media (max-width: 600px) {
            .metrics-grid { grid-template-columns: repeat(2, 1fr); }
        }

        .panel__head {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .panel__head-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .panel__head-row .panel__title {
            font-size: 13px;
            color: #64748b;
            font-weight: 500;
        }

        .panel__link-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: opacity 0.2s;
        }
        .panel__link-btn:hover {
            opacity: 0.8;
        }
        .panel__link-btn svg {
            flex-shrink: 0;
        }

        .accounts-sort {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .accounts-sort__btn {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
            padding: 6px 12px;
            border-radius: 6px;
            background: transparent;
            transition: all 0.2s;
        }
        .accounts-sort__btn:hover {
            color: #94a3b8;
            background: rgba(39, 39, 47, 0.3);
        }
        .accounts-sort__btn.active {
            color: #d4af37;
            background: rgba(39, 39, 47, 0.5);
        }
        .accounts-toolbar {
            position: relative;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: space-between;
        }
        .accounts-toolbar .accounts-sort {
            margin-right: auto;
        }
        .accounts-status-select {
            display: flex;
            align-items: center;
            gap: 6px;
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
        }
        .accounts-status__btn {
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            padding: 6px 12px;
            border-radius: 6px;
            background: transparent;
            transition: all 0.2s;
        }
        .accounts-status__btn--green { color: #64748b; }
                .accounts-status__btn--green:hover { color: #4ade80; background: rgba(74, 222, 128, 0.15); }
        .accounts-status__btn--green.active { color: #4ade80; background: rgba(74, 222, 128, 0.25); }
        .accounts-status__btn--orange { color: #64748b; }
        .accounts-status__btn--orange:hover { color: #fb923c; background: rgba(251, 146, 60, 0.15); }
        .accounts-status__btn--orange.active { color: #fb923c; background: rgba(251, 146, 60, 0.25); }
        .accounts-status__btn--red { color: #64748b; }
        .accounts-status__btn--red:hover { color: #f87171; background: rgba(248, 113, 113, 0.15); }
        .accounts-status__btn--red.active { color: #f87171; background: rgba(248, 113, 113, 0.25); }
        .accounts-actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .accounts-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            padding: 0;
            border: none;
            border-radius: 6px;
            background: transparent;
            color: #94a3b8;
            cursor: pointer;
            transition: all 0.2s;
        }
        .accounts-action-btn:hover {
            background: rgba(39, 39, 47, 0.5);
            color: #94a3b8;
        }
        .accounts-action-btn--delete:hover {
            background: rgba(248, 113, 113, 0.2);
            color: #f87171;
        }
        .accounts-filters {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .accounts-filter__select {
            font-size: 12px;
            font-weight: 600;
            color: #94a3b8;
            background: #141210;
            border: 1px solid #27272f;
            border-radius: 6px;
            padding: 6px 10px;
            cursor: pointer;
        }
        .accounts-pagination {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            padding: 16px;
            margin-top: 8px;
        }
        .accounts-pagination__nav {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .accounts-pagination__info {
            font-size: 12px;
            color: #64748b;
        }
        .accounts-pagination__btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            padding: 0 8px;
            font-size: 13px;
            font-weight: 600;
            color: #94a3b8;
            background: #141210;
            border: 1px solid #27272f;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .accounts-pagination__btn:hover {
            color: #d4af37;
            background: rgba(39, 39, 47, 0.6);
            border-color: #5c4d2e;
        }
        .accounts-pagination__btn.active {
            color: #fff;
            background: #5c4d2e;
            border-color: #5c4d2e;
            pointer-events: none;
        }
        .accounts-pagination__btn--arrow {
            font-weight: 800;
        }
        .accounts-pagination__btn--disabled {
            opacity: 0.4;
            filter: blur(0.5px);
            cursor: default;
            pointer-events: none;
        }
        .accounts-pagination__dots {
            font-size: 13px;
            color: #64748b;
            padding: 0 4px;
        }
        .modal {
            position: fixed;
            inset: 0;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s, visibility 0.2s;
        }
        .modal.open {
            opacity: 1;
            visibility: visible;
        }
        .modal__overlay {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            cursor: pointer;
        }
        .modal__box {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            background: #141210;
            border: 1px solid #27272f;
            border-radius: 12px;
            padding: 24px;
            min-width: 320px;
        }
        .modal__text {
            font-size: 16px;
            font-weight: 600;
            color: #e2e8f0;
            margin: 0 0 16px;
            text-align: center;
        }
        .modal__info {
            margin-bottom: 20px;
            text-align: center;
            font-size: 14px;
            color: #e2e8f0;
        }
        .modal__info-row {
            margin-bottom: 4px;
        }
        .modal__id-hash {
            color: #d4af37;
        }
        .accounts-process {
            color: #fb923c;
            font-weight: 700;
        }
        .accounts-error {
            color: #f87171;
            font-weight: 700;
        }
        .accounts-table td.accounts-empty-msg {
            text-align: center;
            vertical-align: middle;
            padding: 32px 24px;
            color: #94a3b8;
            font-weight: 700;
        }
        .accounts-cleanup-notice {
            margin: 0 0 16px;
            text-align: center;
            font-size: 14px;
            font-weight: 700;
            color: #fb923c;
        }
        .modal__actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        .modal__btn {
            padding: 8px 20px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .modal__btn--cancel {
            background: transparent;
            border: 1px solid #27272f;
            color: #94a3b8;
        }
        .modal__btn--cancel:hover {
            background: #27272f;
            color: #e2e8f0;
        }
        .modal__btn--confirm {
            background: #f87171;
            border: none;
            color: #fff;
        }
        .modal__btn--confirm:hover {
            background: #ef4444;
        }
        .modal__box--wide { min-width: 640px; max-width: 90vw; }
        .manage-modal__body { display: flex; gap: 24px; width: 100%; margin-bottom: 20px; }
        .manage-modal__left { flex: 1; min-width: 0; }
        .manage-modal__right { flex: 0 0 280px; min-width: 0; }
        .manage-modal__acc-wrap { margin-top: 12px; }
        .manage-modal__acc-item { margin-bottom: 12px; font-size: 14px; }
        .manage-modal__acc-label { color: #64748b; }
        .manage-modal__acc-value { color: #e2e8f0; font-weight: 600; }
        .manage-modal__sep { color: #d4af37; }
        .modal__text .manage-modal__title-id { color: #d4af37 !important; }
        .modal__text .manage-modal__title-num { color: #fff; }
        .manage-modal__acc-card { border: 1px solid #27272f; border-radius: 12px; padding: 16px; margin-top: 12px; margin-bottom: 12px; }
        .manage-modal__check-card {
            border: 1px solid #27272f;
            border-radius: 12px;
            padding: 16px;
            margin-top: 16px;
        }
        .manage-modal__check-card .manage-modal__acc-wrap { margin-top: 0; margin-bottom: 0; }
        .manage-modal__check-btn {
            display: block; width: 100%; padding: 10px 16px; margin-bottom: 12px;
            box-sizing: border-box;
            font-size: 14px; font-weight: 600; border-radius: 8px; cursor: pointer;
            background: rgba(39, 39, 47, 0.5); border: 1px solid #27272f; color: #94a3b8;
        }
        .manage-modal__check-btn:hover { background: rgba(39, 39, 47, 0.5); color: #d4af37; }
        .manage-modal__check-btn:disabled { cursor: not-allowed; opacity: 0.8; display: flex; align-items: center; justify-content: center; }
        .manage-modal__check-btn .manage-modal__spinner { display: block; }
        .manage-modal__check-btn .manage-modal__checkmark { display: block; }
        .manage-modal__check-btn.manage-modal__check-btn--success { background: #22c55e !important; border-color: #22c55e !important; color: #fff !important; transition: background 0.3s, border-color 0.3s, color 0.3s; }
        .manage-modal__check-btn.manage-modal__check-btn--error { background: #ef4444 !important; border-color: #ef4444 !important; color: #fff !important; transition: background 0.3s, border-color 0.3s, color 0.3s; }
        @keyframes checkmark-draw { to { stroke-dashoffset: 0; } }
        .manage-modal__check-btn .manage-modal__checkmark path { animation: checkmark-draw 0.5s ease-out forwards; }
        .manage-modal__check-btn .manage-modal__cross { display: block; }
        .manage-modal__check-btn .manage-modal__cross path { animation: checkmark-draw 0.5s ease-out forwards; }
        .modal__btn--primary {
            background: #3d3520;
            border: none;
            color: #fff;
        }
        .modal__btn--primary:hover { background: #5c4d2e; color: #d4af37; }
        .modal__actions--col { flex-direction: column; }
        .modal__box--sm { max-width: 420px; }
        .qr-auth-drop { position: relative; min-height: 180px; border: 2px dashed #27272f; border-radius: 12px; padding: 24px; cursor: pointer; transition: border-color 0.2s, background 0.2s; display: flex; align-items: center; justify-content: center; }
        .qr-auth-drop:hover, .qr-auth-drop.dragover { border-color: #5c4d2e; background: rgba(39, 39, 47, 0.3); }
        .qr-auth-drop__text { color: #64748b; font-size: 14px; text-align: center; }
        .qr-auth-drop__preview { position: relative; width: 100%; min-height: 160px; display: flex; align-items: center; justify-content: center; }
        .qr-auth-drop__preview img { max-width: 100%; max-height: 200px; border-radius: 8px; }
        .qr-auth-drop__clear { position: absolute; top: -8px; right: -8px; width: 28px; height: 28px; border-radius: 50%; border: none; background: #ef4444; color: #fff; font-size: 18px; line-height: 1; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 0; transition: background 0.2s; }
        .qr-auth-drop__clear:hover { background: #dc2626; }
        html.theme-light .qr-auth-drop { border-color: #c9c4bc; }
        html.theme-light .qr-auth-drop:hover, html.theme-light .qr-auth-drop.dragover { border-color: #b8a078; background: #e8e0d0; }
        html.theme-light .qr-auth-drop__text { color: #5c574f; }
        .qr-auth-actions { margin-top: 20px; }
        .qr-auth-actions .modal__btn { width: 100%; }
        .qr-auth-cancel { margin-top: 0; }
        .settings-tabs { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; border-bottom: 1px solid #27272f; }
        .settings-tabs__nav { display: flex; gap: 4px; }
        .settings-license { font-size: 14px; font-weight: 700; color: #64748b; }
        .settings-license__value { color: #d4af37; font-weight: 600; }
        .settings-tabs__btn { padding: 14px 24px; font-size: 16px; font-weight: 700; color: #64748b; background: none; border: none; border-bottom: 3px solid transparent; margin-bottom: -1px; cursor: pointer; transition: color 0.2s, border-color 0.2s; }
        .settings-tabs__btn:hover { color: #94a3b8; }
        .settings-tabs__btn.active { color: #d4af37; border-bottom-color: #d4af37; }
        .settings-tab-panel { display: none; }
        .settings-tab-panel.active { display: block; }
        .settings-placeholder { color: #64748b; font-size: 14px; margin: 0; }
        .settings-form { display: flex; flex-direction: column; gap: 16px; }
        .settings-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .settings-label { font-size: 14px; color: #e2e8f0; }
        .settings-input { padding: 8px 12px; border-radius: 8px; border: 1px solid #27272f; background: #161410; color: #e2e8f0; font-size: 14px; min-width: 200px; }
        .settings-input:disabled { opacity: 0.6; cursor: not-allowed; }
        .settings-select { padding: 8px 12px; border-radius: 8px; border: 1px solid #27272f; background: #161410; color: #e2e8f0; font-size: 14px; min-width: 200px; cursor: pointer; }
        .settings-block { margin-bottom: 24px; }
        .settings-block:last-child { margin-bottom: 0; }
        .settings-toast-container { position: fixed; bottom: 24px; right: 24px; display: flex; flex-direction: column; align-items: flex-end; gap: 8px; z-index: 10000; pointer-events: none; }
        .settings-toast { padding: 12px 20px; background: #22c55e; color: #fff; font-size: 14px; font-weight: 600; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); opacity: 0; transform: translateY(10px); transition: opacity 0.3s, transform 0.3s; }
        .settings-toast.visible { opacity: 1; transform: translateY(0); }
        .settings-toast.toast--error { background: #ef4444; }
        .settings-toggle { position: relative; display: inline-block; width: 44px; height: 24px; }
        .settings-toggle input { opacity: 0; width: 0; height: 0; }
        .settings-toggle__slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background: #27272f; border-radius: 24px; transition: 0.3s; }
        .settings-toggle__slider:before { content: ''; position: absolute; height: 18px; width: 18px; left: 3px; bottom: 3px; background: #64748b; border-radius: 50%; transition: 0.3s; }
        .settings-toggle input:checked + .settings-toggle__slider { background: #5c4d2e; }
        .settings-toggle input:checked + .settings-toggle__slider:before { transform: translateX(20px); background: #fff; }
        .manage-modal__info { width: 100%; margin-bottom: 20px; }
        .manage-modal__row { margin-bottom: 12px; }
        .manage-modal__row-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px; }
        .manage-modal__label { font-size: 12px; color: #64748b; }
        .manage-modal__copy {
            display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px;
            padding: 0; border: none; border-radius: 6px; background: transparent; color: #64748b;
            cursor: pointer; transition: all 0.2s;
        }
        .manage-modal__copy:hover { background: rgba(39, 39, 47, 0.5); color: #d4af37; }
        .manage-modal__value {
            display: block;
            font-size: 12px;
            word-break: break-all;
            background: #0d0c09;
            padding: 8px 12px;
            border-radius: 6px;
            color: #94a3b8;
        }
        .manage-modal__value--pre {
            white-space: pre-wrap;
            max-height: 180px;
            overflow-y: auto;
            font-size: 11px;
        }

        .chart-legend {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .chart-legend__item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #94a3b8;
        }

        .chart-legend__dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .chart-wrap {
            position: relative;
            width: 100%;
            height: 260px;
            min-width: 0;
            overflow: hidden;
        }

        .chart-wrap--sm { height: 220px; }
        .chart-wrap--offset { margin-top: 34px; }

        .chart-wrap--lg { height: 320px; }

        .offer-highlight {
            text-align: center;
            padding: 24px 16px;
        }
        .offer-highlight__name {
            font-size: 13px;
            color: #94a3b8;
            margin-bottom: 8px;
        }
        .offer-highlight__value {
            font-size: 28px;
            font-weight: 700;
        }
        .offer-highlight__label {
            font-size: 12px;
            color: #64748b;
        }
        .bars-list {
            list-style: none;
        }

        .bars-list li {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            font-size: 13px;
        }

        .bars-list li:last-child { margin-bottom: 0; }

        .bars-list .name { color: #94a3b8; flex: 0 0 120px; max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .bars-list .bar-wrap { flex: 1; height: 8px; background: #27272f; border-radius: 4px; overflow: hidden; min-width: 0; }
        .bars-list .bar { height: 100%; border-radius: 4px; min-width: 4px; }
        .bars-list .val { color: #f1f5f9; font-weight: 600; min-width: 70px; text-align: right; font-variant-numeric: tabular-nums; }

        .accounts-table-wrap {
            overflow-x: auto;
        }

        .accounts-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .accounts-table th,
        .accounts-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #27272f;
        }

        .accounts-table__slash {
            color: #d4af37;
        }
        .accounts-table th {
            color: #64748b;
            font-weight: 700;
        }

        .accounts-table td {
            color: #e2e8f0;
            font-weight: 600;
        }

        .accounts-table tbody tr:hover {
            background: rgba(39, 39, 47, 0.3);
        }

        .accounts-table .status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
        }

        .accounts-table .status--success {
            background: rgba(74, 222, 128, 0.15);
            color: #4ade80;
        }

        .accounts-table .status--pending {
            background: rgba(251, 146, 60, 0.15);
            color: #fb923c;
        }

        .accounts-table .status--fail {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
        }

        @media (max-width: 900px) {
            .charts-section {
                grid-template-columns: 1fr;
                min-width: 0;
            }
            .charts-section .panel { min-width: 0; }
        }

        @media (max-width: 600px) {
            body { overflow-x: hidden; }
            .content { padding: 16px; overflow-x: hidden; max-width: 100%; }
            .stats-strip-wrap { margin-bottom: 24px; }
            .stats-strip-wrap .stats-strip {
                display: grid;
                grid-template-columns: 1fr 1fr;
                grid-template-rows: 1fr 1fr;
                aspect-ratio: 2 / 1;
                padding: 0;
                gap: 0;
                border-radius: 6px;
                overflow: hidden;
            }
            .stats-strip-wrap .stat-item {
                order: 0;
                min-width: auto;
                padding: 14px 12px;
                border-right: 1px solid #27272f;
                border-bottom: 1px solid #27272f;
                background: #141210;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                text-align: center;
            }
            .stats-strip-wrap .stat-item:nth-child(1) { order: 1; }
            .stats-strip-wrap .stat-item:nth-child(4) { order: 2; }
            .stats-strip-wrap .stat-item:nth-child(2) { order: 3; }
            .stats-strip-wrap .stat-item:nth-child(3) { order: 4; }
            .stats-strip-wrap .stat-item:nth-child(3),
            .stats-strip-wrap .stat-item:nth-child(4) { border-right: none; }
            .stats-strip-wrap .stat-item:nth-child(2),
            .stats-strip-wrap .stat-item:nth-child(3) { border-bottom: none; }
            .stats-strip-wrap .stat-item__value { font-size: 22px; font-weight: 700; }
            .stats-strip-wrap .stat-item__label { font-size: 11px; font-weight: 700; }
            .panel .stats-strip { flex-direction: column; padding: 12px; }
            .panel .stat-item { border-right: none; border-bottom: 1px solid #27272f; min-width: auto; }
            .panel .stat-item:last-child { border-bottom: none; }
            .charts-section { min-width: 0; }
            .charts-section .panel:first-child .panel__head-row {
                flex-direction: column;
                align-items: center;
                text-align: center;
                gap: 12px;
            }
            .charts-section .panel:first-child .panel__title {
                font-size: 15px;
                font-weight: 700;
            }
            .charts-section .panel:first-child .chart-legend {
                justify-content: center;
            }
            .charts-section .panel:first-child .chart-legend__item {
                font-weight: 700;
            }
            .charts-section .panel:nth-child(2) .panel__head {
                text-align: center;
                font-size: 15px;
                font-weight: 700;
            }
            .charts-section + .panel .panel__title {
                font-weight: 700;
            }
            #view-stats .stats-strip-wrap + .panel .panel__head-row {
                flex-direction: column;
                align-items: center;
                text-align: center;
                gap: 12px;
            }
            #view-stats .stats-strip-wrap + .panel .panel__title {
                font-size: 15px;
                font-weight: 700;
            }
            #view-stats .stats-strip-wrap + .panel .chart-legend {
                justify-content: center;
            }
            #view-stats .stats-strip-wrap + .panel .chart-legend__item {
                font-weight: 700;
            }
            #view-stats .panel:nth-child(4) .panel__head,
            #view-stats .panel:nth-child(5) .panel__head {
                font-weight: 700;
            }
            .accounts-sort { flex-wrap: wrap; gap: 6px; }
            .accounts-sort__btn { font-size: 11px; padding: 5px 10px; }
            .accounts-status-select { position: static; transform: none; }
            .accounts-status__btn { font-size: 11px; padding: 5px 10px; }
            #view-accounts .accounts-toolbar {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
                padding-bottom: 4px;
            }
            #view-accounts .accounts-toolbar .accounts-sort {
                margin-right: 0;
                justify-content: center;
            }
            #view-accounts .accounts-status-select {
                justify-content: center;
            }
            #view-accounts .accounts-filters {
                flex-wrap: wrap;
                justify-content: center;
                gap: 6px;
            }
            #view-accounts .accounts-filter__select {
                font-size: 11px;
                padding: 5px 8px;
                min-width: 0;
            }
            #view-accounts .panel { padding: 12px; }
            #view-accounts .accounts-table th,
            #view-accounts .accounts-table td {
                padding: 10px 8px;
                font-size: 12px;
            }
            #view-accounts .accounts-table .status {
                font-size: 11px;
                padding: 3px 8px;
            }
            #view-accounts .accounts-table-wrap {
                margin: 0 -12px;
                padding: 0 12px;
                -webkit-overflow-scrolling: touch;
            }
            #view-accounts .accounts-table {
                min-width: 360px;
            }
            #view-accounts .accounts-pagination {
                padding: 12px 8px;
                gap: 8px;
            }
            #view-accounts .accounts-pagination__nav { gap: 6px; }
            #view-accounts .accounts-pagination__btn {
                min-width: 28px;
                height: 28px;
                font-size: 12px;
            }
            #view-accounts .dashboard-title {
                text-align: center;
                font-weight: 700;
            }
            .chart-wrap {
                height: 220px;
                min-width: 0;
                overflow: hidden;
            }
            .chart-wrap--sm { height: 200px; }
            .chart-wrap--lg { height: 260px; }
            .panel { padding: 16px; }
        }

        .header__right {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .theme-toggle {
            display: flex; align-items: center; justify-content: center;
            width: 40px; height: 40px;
            background: rgba(39, 39, 47, 0.5);
            border: 1px solid #27272f;
            border-radius: 10px;
            color: #94a3b8;
            cursor: pointer;
            transition: all 0.2s;
        }
        .theme-toggle:hover { color: #d4af37; background: rgba(39, 39, 47, 0.8); border-color: #3d3520; }
        .theme-toggle svg { width: 20px; height: 20px; }
        .theme-toggle__moon { display: none; }
        html.theme-light .theme-toggle__sun { display: none; }
        html.theme-light .theme-toggle__moon { display: block; }
        html.theme-light .theme-toggle { color: #64748b; }
        html.theme-light .theme-toggle:hover { color: #c9a227; }

        html.theme-light body { background: #d9d5d0; color: #2d2a26; }
        html.theme-light .logo { color: #2d2a26; }
        html.theme-light .logo:hover { color: #c9a227; }
        html.theme-light .nav-menu__block { background: #e8e4df; border-color: #c9c4bc; }
        html.theme-light .nav-menu__block:hover { background: #e0dcd6; border-color: #b8b2a8; }
        html.theme-light .nav-button { color: #5c574f; }
        html.theme-light .nav-button:hover { color: #2d2a26; background: #d4cfc8; }
        html.theme-light .nav-button.active { color: #c9a227; background: #e8e0d0; }
        html.theme-light .user-menu-trigger { background: #e8e4df; border-color: #c9c4bc; color: #5c574f; }
        html.theme-light .user-menu-trigger:hover { background: #e0dcd6; border-color: #b8b2a8; color: #2d2a26; }
        html.theme-light .theme-toggle { background: #e8e4df; border-color: #c9c4bc; color: #5c574f; }
        html.theme-light .theme-toggle:hover { background: #e0dcd6; border-color: #b8b2a8; }
        html.theme-light .user-menu-dropdown { background: #e8e4df; border-color: #c9c4bc; }
        html.theme-light .user-menu-item { color: #5c574f; }
        html.theme-light .user-menu-item:hover { background: #e8d8d8; color: #b91c1c; }
        html.theme-light .dashboard-title { color: #5c574f; }
        html.theme-light .stats-strip, html.theme-light .panel, html.theme-light .period-select,
        html.theme-light .accounts-sort, html.theme-light .accounts-status-select,
        html.theme-light .accounts-filter__select, html.theme-light .accounts-pagination__btn,
        html.theme-light .accounts-action-btn, html.theme-light .accounts-sort__btn,
        html.theme-light .accounts-status__btn {
            background: #e8e4df !important;
            border-color: #c9c4bc !important;
        }
        html.theme-light .accounts-action-btn:hover { background: #d4cfc8 !important; color: #c9a227 !important; }
        html.theme-light .accounts-action-btn--delete:hover { background: #e8d8d8 !important; color: #dc2626 !important; }
        html.theme-light .stat-item { border-color: #c9c4bc !important; }
        html.theme-light .stat-item__label, html.theme-light .stat-item__value { color: #4a4540 !important; }
        html.theme-light .stat-item__value--cyan { color: #0ea5e9 !important; }
        html.theme-light .stat-item__value--lime { color: #16a34a !important; }
        html.theme-light .stat-item__value--orange { color: #ea580c !important; }
        html.theme-light .stat-item__value--red { color: #dc2626 !important; }
        html.theme-light .period-select__btn, html.theme-light .accounts-sort__btn,
        html.theme-light .accounts-status__btn { color: #5c574f !important; }
        html.theme-light .period-select__btn:hover, html.theme-light .accounts-sort__btn:hover { color: #2d2a26; background: #d4cfc8 !important; }
        html.theme-light .period-select__btn.active, html.theme-light .accounts-sort__btn.active { color: #c9a227; background: #e8e0d0 !important; }
        html.theme-light .accounts-status__btn--green.active { color: #16a34a !important; }
        html.theme-light .accounts-status__btn--orange.active { color: #ea580c !important; }
        html.theme-light .accounts-status__btn--red.active { color: #dc2626 !important; }
        html.theme-light .accounts-table th, html.theme-light .accounts-table td { border-color: #c9c4bc; color: #3d3934; }
        html.theme-light .accounts-table tbody tr:hover { background: #e0dcd6; }
        html.theme-light .modal__overlay { background: rgba(45, 42, 38, 0.4); }
        html.theme-light .modal__box { background: #e8e4df; border-color: #c9c4bc; }
        html.theme-light .modal__text { color: #2d2a26; }
        html.theme-light .manage-modal__acc-label, html.theme-light .manage-modal__label { color: #5c574f; }
        html.theme-light .manage-modal__acc-value, html.theme-light .manage-modal__value { color: #2d2a26; }
        html.theme-light .manage-modal__value { background: #e0dcd6; color: #2d2a26; }
        html.theme-light .manage-modal__acc-card, html.theme-light .manage-modal__check-card { border-color: #c9c4bc; }
        html.theme-light .manage-modal__check-btn { background: #e0dcd6; border-color: #c9c4bc; color: #5c574f; }
        html.theme-light .manage-modal__check-btn:hover { background: #e8e0d0; border-color: #b8a078; color: #c9a227; }
        html.theme-light .modal__btn--primary { background: #b8860b; border-color: #b8860b; color: #fff; }
        html.theme-light .modal__btn--primary:hover { background: #9a7209; }
        html.theme-light .settings-tabs { border-color: #c9c4bc; }
        html.theme-light .settings-tabs__btn { color: #5c574f; }
        html.theme-light .settings-tabs__btn.active { color: #c9a227; border-color: #c9a227; }
        html.theme-light .settings-label, html.theme-light .settings-input, html.theme-light .settings-select { color: #3d3934; }
        html.theme-light .settings-input, html.theme-light .settings-select { background: #e0dcd6; border-color: #c9c4bc; }
        html.theme-light .settings-toggle__slider { background: #c9c4bc; }
        html.theme-light .settings-toggle input:checked + .settings-toggle__slider { background: #b8860b; }
        .nav-menu__logout {
            display: none;
        }

        @media (max-width: 768px) {
            .header {
                flex-wrap: wrap;
            }
            .nav-menu__logout {
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 14px 16px;
                margin-top: auto;
                border-top: 1px solid #27272f;
                color: #fca5a5;
                text-decoration: none;
                font-weight: 600;
                font-size: 15px;
                border-radius: 8px;
                transition: all 0.2s;
            }
            .nav-menu__logout:hover {
                background: rgba(220, 38, 38, 0.15);
                color: #fecaca;
            }
            #view-accounts .accounts-toolbar {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            #view-accounts .accounts-toolbar .accounts-sort {
                margin-right: 0;
                justify-content: center;
            }
            #view-accounts .accounts-status-select {
                position: static;
                transform: none;
                justify-content: center;
            }
            #view-accounts .accounts-filters {
                flex-wrap: wrap;
                justify-content: center;
            }
            .nav-menu {
                position: fixed;
                top: 0;
                right: 0;
                bottom: 0;
                width: min(220px, 72vw);
                background: #141210;
                border-left: 1px solid #27272f;
                padding: 80px 16px 24px;
                flex-direction: column;
                align-items: stretch;
                z-index: 1000;
                transform: translateX(100%);
                transition: transform 0.3s ease;
                box-shadow: -8px 0 24px rgba(0, 0, 0, 0.4);
            }
            .nav-menu.open {
                transform: translateX(0);
            }
            .nav-menu__block {
                flex-direction: column;
                gap: 4px;
                padding: 8px;
                background: transparent;
                border: none;
            }
            .nav-button {
                padding: 14px 16px;
                font-size: 15px;
                gap: 10px;
                justify-content: flex-start;
                border-radius: 8px;
            }
            .nav-button svg {
                width: 20px;
                height: 20px;
            }
            .nav-menu {
                flex-direction: column;
            }
        }
        .nav-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            opacity: 0;
            transition: opacity 0.3s;
        }
            .nav-overlay.open {
                display: block;
                opacity: 1;
            }

        @media (max-width: 480px) {
            .header {
                padding: 12px 16px;
            }

            .logo {
                font-size: 18px;
            }

            .user-menu-trigger {
                padding: 8px 12px;
                font-size: 13px;
                gap: 6px;
            }
            .user-menu-trigger span {
                max-width: 80px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .user-menu-trigger svg {
                width: 14px;
                height: 14px;
            }
        }

        .content-view {
            display: none;
        }

        .content-view.active {
            display: block;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stats-grid .stat-item {
            min-width: auto;
            border-right: none;
            border-bottom: none;
        }

        .stats-grid .panel {
            grid-column: span 1;
        }

        .stats-grid .panel.wide {
            grid-column: span 2;
        }

        .panel-stats {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .panel-stats__row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
            border-bottom: 1px solid #27272f;
            font-size: 13px;
        }

        .panel-stats__row:last-child {
            border-bottom: none;
        }

        .panel-stats__label {
            color: #64748b;
        }

        .panel-stats__val {
            font-weight: 600;
            color: #f1f5f9;
            font-variant-numeric: tabular-nums;
        }

        .panel-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px 24px;
        }

        .panel-info-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .panel-info-item__label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .panel-info-item__val {
            font-size: 15px;
            font-weight: 600;
            color: #f1f5f9;
        }

        @media (max-width: 900px) {
            .stats-grid .panel.wide {
                grid-column: span 1;
            }
            .panel-info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div id="settingsToastContainer" class="settings-toast-container" aria-live="polite"></div>
    <header class="header">
        <a href="/dashboard.php" class="logo">INVADER PANEL</a>
        
        <nav class="nav-menu" id="navMenu">
            <div class="nav-menu__block">
            <a href="#" class="nav-button <?php echo !isset($_GET['stats_period']) && !$showAccounts && !$showSettings ? 'active' : ''; ?>" data-view="main">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                    <polyline points="9 22 9 12 15 12 15 22"/>
                </svg>
                Главная
            </a>
            <a href="#" class="nav-button <?php echo isset($_GET['stats_period']) && !$showAccounts && !$showSettings ? 'active' : ''; ?>" data-view="stats">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <line x1="18" y1="20" x2="18" y2="10"/>
                    <line x1="12" y1="20" x2="12" y2="4"/>
                    <line x1="6" y1="20" x2="6" y2="14"/>
                </svg>
                Статистика
            </a>
            <a href="#" class="nav-button <?php echo $showAccounts && !$showSettings ? 'active' : ''; ?>" data-view="accounts">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                Аккаунты
            </a>
            <a href="#" class="nav-button">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/>
                    <line x1="7" y1="7" x2="7.01" y2="7"/>
                </svg>
                Офферы
            </a>
            <a href="#" class="nav-button">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="2" y1="12" x2="22" y2="12"/>
                    <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                </svg>
                Домены
            </a>
            <a href="#" class="nav-button <?php echo $showSettings ? 'active' : ''; ?>" data-view="settings">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                </svg>
                Настройки
            </a>
            </div>
            <a href="/vendor/auth/logout.php" class="nav-menu__logout">Выйти</a>
        </nav>

        <div class="nav-overlay" id="navOverlay" aria-hidden="true"></div>
        
        <div class="header__right">
            <button type="button" class="theme-toggle" id="themeToggle" title="Светлая / Тёмная тема" aria-label="Переключить тему">
                <svg class="theme-toggle__sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
                <svg class="theme-toggle__moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </button>
            <div class="user-menu" id="userMenu">
                <button class="user-menu-trigger" id="userMenuTrigger" onclick="handleUserMenuClick(event)">
                    <span><?php echo htmlspecialchars($user['login']); ?></span>
                    <svg viewBox="0 0 20 20" aria-hidden="true">
                        <path d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.4z"></path>
                    </svg>
                </button>
                <div class="user-menu-dropdown" id="userMenuDropdown">
                    <a href="/vendor/auth/logout.php" class="user-menu-item">Выйти</a>
                </div>
            </div>
        </div>
    </header>

    <div class="content">
        <!-- Главная -->
        <div class="content-view <?php echo !isset($_GET['stats_period']) && !$showAccounts && !$showSettings ? 'active' : ''; ?>" id="view-main">
        <h1 class="dashboard-title">Главная</h1>

        <div class="stats-strip-wrap">
            <div class="stats-strip">
                <div class="stat-item">
                    <div class="stat-item__value stat-item__value--cyan"><?php echo number_format($statsAttempts, 0, '', ' '); ?></div>
                    <div class="stat-item__label">Попыток авторизации</div>
                </div>
                <div class="stat-item">
                    <div class="stat-item__value stat-item__value--lime"><?php echo number_format($statsSuccess, 0, '', ' '); ?></div>
                    <div class="stat-item__label">Успешные</div>
                </div>
                <div class="stat-item">
                    <div class="stat-item__value stat-item__value--red"><?php echo number_format($statsFail, 0, '', ' '); ?></div>
                    <div class="stat-item__label">Ошибки</div>
                </div>
                <div class="stat-item">
                    <div class="stat-item__value" style="color:#94a3b8"><?php echo number_format($statsOffers, 0, '', ' '); ?></div>
                    <div class="stat-item__label">Офферов в работе</div>
                </div>
            </div>
        </div>

        <div class="charts-section">
            <div class="panel">
                <div class="panel__head panel__head-row">
                    <span class="panel__title">Авторизации за сутки</span>
                    <div class="chart-legend">
                        <span class="chart-legend__item"><span class="chart-legend__dot" style="background:#22d3ee"></span>Попытки</span>
                        <span class="chart-legend__item"><span class="chart-legend__dot" style="background:#4ade80"></span>Успешные</span>
                        <span class="chart-legend__item"><span class="chart-legend__dot" style="background:#f87171"></span>Ошибки</span>
                    </div>
                </div>
                <div class="chart-wrap">
                    <canvas id="lineChart"></canvas>
                </div>
            </div>
            <div class="panel">
                <div class="panel__head">Офферы</div>
                <div class="chart-wrap chart-wrap--sm chart-wrap--offset">
                    <canvas id="doughnutChart"></canvas>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel__head panel__head-row">
                <span class="panel__title">Последние аккаунты</span>
                <a href="#" class="panel__link-btn" style="color:#94a3b8">
                    Все аккаунты
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>
            <div class="accounts-table-wrap">
                <table class="accounts-table">
                    <thead>
                        <tr>
                            <th>№</th>
                            <th>Номер</th>
                            <th>Статистика аккаунта</th>
                            <th>Оффер</th>
                            <th>Дата</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $num = 1;
                        foreach (array_slice($lastAccounts, 0, 10) as $acc):
                            $dateFormatted = date('d.m.Y H:i', strtotime($acc['created_at']));
                            $statusLabel = $statusLabels[$acc['status']] ?? '—';
                            $statusClass = $statusClasses[$acc['status']] ?? 'status--pending';
                        ?>
                        <tr>
                            <td><?php echo $num++; ?></td>
                            <td><?php echo htmlspecialchars($acc['phone']); ?></td>
                            <td><span style="color:#94a3b8"><?php echo (int) $acc['chats']; ?></span> чатов<span style="color:#94a3b8">,</span> <span style="color:#94a3b8"><?php echo (int) $acc['contacts']; ?></span> контакта</td>
                            <td><?php echo htmlspecialchars($acc['offer_name']); ?></td>
                            <td><?php echo $dateFormatted; ?></td>
                            <td><span class="status <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($lastAccounts)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center;color:#64748b;padding:24px;">Нет аккаунтов</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        </div>

        <!-- Аккаунты -->
        <div class="content-view <?php echo $showAccounts && !$showSettings ? 'active' : ''; ?>" id="view-accounts">
            <h1 class="dashboard-title">Аккаунты</h1>
            <div class="panel">
                <div class="panel__head panel__head-row accounts-toolbar">
                    <div class="accounts-sort">
                        <?php
                        $orderNext = $accountsOrder === 'desc' ? 'asc' : 'desc';
                        $bp = function($sort) use ($accountsSort, $orderNext, $accountsFilterStatus, $accountsFilterDate, $accountsFilterGeo, $accountsFilterChats) {
                            $p = ['accounts_sort' => $sort];
                            if ($accountsSort === $sort) $p['accounts_order'] = $orderNext;
                            if ($accountsFilterStatus !== 'active') $p['accounts_filter_status'] = $accountsFilterStatus;
                            if ($accountsFilterDate !== 'all') $p['accounts_filter_date'] = $accountsFilterDate;
                            if ($accountsFilterGeo) $p['accounts_filter_geo'] = $accountsFilterGeo;
                            if ($accountsFilterChats) $p['accounts_filter_chats'] = $accountsFilterChats;
                            return '?' . http_build_query($p);
                        };
                        $pp = function($page) use ($accountsSort, $accountsOrder, $accountsFilterStatus, $accountsFilterDate, $accountsFilterGeo, $accountsFilterChats) {
                            $p = ['accounts_sort' => $accountsSort, 'accounts_order' => $accountsOrder];
                            if ($accountsFilterStatus !== 'active') $p['accounts_filter_status'] = $accountsFilterStatus;
                            if ($accountsFilterDate !== 'all') $p['accounts_filter_date'] = $accountsFilterDate;
                            if ($accountsFilterGeo) $p['accounts_filter_geo'] = $accountsFilterGeo;
                            if ($accountsFilterChats) $p['accounts_filter_chats'] = $accountsFilterChats;
                            if ($page > 1) $p['accounts_page'] = $page;
                            return '?' . http_build_query($p);
                        };
                        ?>
                        <a href="<?php echo $bp('chats'); ?>" class="accounts-sort__btn <?php echo $accountsSort === 'chats' ? 'active' : ''; ?>">По чатам</a>
                        <a href="<?php echo $bp('date'); ?>" class="accounts-sort__btn <?php echo $accountsSort === 'date' ? 'active' : ''; ?>">По дате</a>
                        <a href="<?php echo $bp('geo'); ?>" class="accounts-sort__btn <?php echo $accountsSort === 'geo' ? 'active' : ''; ?>">По ГЕО</a>
                    </div>
                    <div class="accounts-status-select">
                        <?php
                        $bsp = function($status) use ($accountsSort, $accountsOrder, $accountsFilterStatus, $accountsFilterGeo, $accountsFilterChats) {
                            $p = ['accounts_sort' => $accountsSort, 'accounts_order' => $accountsOrder];
                            if ($status !== 'active') $p['accounts_filter_status'] = $status;
                            if ($accountsFilterGeo) $p['accounts_filter_geo'] = $accountsFilterGeo;
                            if ($accountsFilterChats) $p['accounts_filter_chats'] = $accountsFilterChats;
                            return '?' . http_build_query($p);
                        };
                        ?>
                        <a href="<?php echo $bsp('active'); ?>" class="accounts-status__btn accounts-status__btn--green <?php echo $accountsFilterStatus === 'active' ? 'active' : ''; ?>">Активные</a>
                        <a href="<?php echo $bsp('in_work'); ?>" class="accounts-status__btn accounts-status__btn--orange <?php echo $accountsFilterStatus === 'in_work' ? 'active' : ''; ?>">В работе</a>
                        <a href="<?php echo $bsp('errors'); ?>" class="accounts-status__btn accounts-status__btn--red <?php echo $accountsFilterStatus === 'errors' ? 'active' : ''; ?>">Ошибки</a>
                    </div>
                    <div class="accounts-filters">
                        <?php
                        $fp = function($overrides = []) use ($accountsSort, $accountsOrder, $accountsFilterStatus, $accountsFilterGeo, $accountsFilterChats) {
                            $p = array_merge([
                                'accounts_sort' => $accountsSort, 'accounts_order' => $accountsOrder,
                                'accounts_filter_status' => $accountsFilterStatus !== 'active' ? $accountsFilterStatus : null,
                                'accounts_filter_geo' => $accountsFilterGeo,
                                'accounts_filter_chats' => $accountsFilterChats ?: null
                            ], $overrides);
                            return '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
                        };
                        ?>
                        <select class="accounts-filter__select" onchange="location.href=this.value">
                            <option value="<?php echo $fp(['accounts_filter_geo' => '']); ?>" <?php echo $accountsFilterGeo === '' ? 'selected' : ''; ?>>Все ГЕО</option>
                            <option value="<?php echo $fp(['accounts_filter_geo' => 'RU']); ?>" <?php echo $accountsFilterGeo === 'RU' ? 'selected' : ''; ?>>RU</option>
                            <option value="<?php echo $fp(['accounts_filter_geo' => 'UA']); ?>" <?php echo $accountsFilterGeo === 'UA' ? 'selected' : ''; ?>>UA</option>
                            <option value="<?php echo $fp(['accounts_filter_geo' => 'KZ']); ?>" <?php echo $accountsFilterGeo === 'KZ' ? 'selected' : ''; ?>>KZ</option>
                            <option value="<?php echo $fp(['accounts_filter_geo' => 'BY']); ?>" <?php echo $accountsFilterGeo === 'BY' ? 'selected' : ''; ?>>BY</option>
                            <option value="<?php echo $fp(['accounts_filter_geo' => 'Other']); ?>" <?php echo $accountsFilterGeo === 'Other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                        <select class="accounts-filter__select" onchange="location.href=this.value">
                            <option value="<?php echo $fp(['accounts_filter_chats' => null]); ?>" <?php echo $accountsFilterChats === 0 ? 'selected' : ''; ?>>Все чаты</option>
                            <option value="<?php echo $fp(['accounts_filter_chats' => 1]); ?>" <?php echo $accountsFilterChats === 1 ? 'selected' : ''; ?>>1+ чатов</option>
                            <option value="<?php echo $fp(['accounts_filter_chats' => 10]); ?>" <?php echo $accountsFilterChats === 10 ? 'selected' : ''; ?>>10+ чатов</option>
                            <option value="<?php echo $fp(['accounts_filter_chats' => 50]); ?>" <?php echo $accountsFilterChats === 50 ? 'selected' : ''; ?>>50+ чатов</option>
                        </select>
                    </div>
                </div>
                <?php if ($accountsFilterStatus === 'errors'): ?>
                <p class="accounts-cleanup-notice">Очистика ошибок происходит каждые 24 часа.</p>
                <?php endif; ?>
                <?php
                $manageDataMap = [];
                foreach ($accountsOnPage as $a) {
                    $decoded = !empty($a['auth_data']) ? json_decode($a['auth_data'], true) : [];
                    $offerText = '';
                    if (!empty($a['offer_id'])) {
                        $offerText = !empty($a['offer_name']) ? ($a['offer_name'] . ' (ID: ' . $a['offer_id'] . ')') : ('ID: ' . $a['offer_id']);
                    }
                    $manageDataMap[(string)$a['id']] = [
                        'auth' => is_array($decoded) ? $decoded : [],
                        'name' => $a['name'] ?? '—',
                        'chats' => (int)($a['chats'] ?? 0),
                        'contacts' => (int)($a['contacts'] ?? 0),
                        'phone' => $a['phone'] ?? '—',
                        'country' => $a['country'] ?? '—',
                        'offer_id' => $a['offer_id'] ?? null,
                        'offer_name' => $a['offer_name'] ?? null,
                        'offer' => $offerText ?: null,
                        'lastAliveCheck' => $a['lastAliveCheck'] ?? null,
                        'created_at' => $a['created_at'] ?? ''
                    ];
                }
                ?>
                <script>window.accountsManageData = <?php echo json_encode($manageDataMap, JSON_UNESCAPED_UNICODE); ?>;</script>
                <div class="accounts-table-wrap">
                    <table class="accounts-table">
                        <thead>
                            <tr>
                                <th>№</th>
                                <th>Номер</th>
                                <th><?php echo $accountsFilterStatus === 'in_work' ? 'Процесс' : ($accountsFilterStatus === 'errors' ? 'Ошибка' : 'Чаты <span class="accounts-table__slash">/</span> Контакты'); ?></th>
                                <th>ГЕО</th>
                                <th>Дата</th>
                                <?php if ($accountsFilterStatus === 'active'): ?><th>Действия</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $num = $accountsOffset + 1;
                            foreach ($accountsOnPage as $acc):
                                $dateFormatted = date('d.m.Y H:i', strtotime($acc['created_at']));
                            ?>
                            <tr>
                                <td><?php echo $num++; ?></td>
                                <td><?php echo htmlspecialchars($acc['phone']); ?></td>
                                <td><?php
                                if ($accountsFilterStatus === 'in_work') {
                                    $as = (int) ($acc['auth_status'] ?? 0);
                                    $proc = $as === 1 ? 'Ожидает отправки SMS кода....' : ($as === 2 ? 'Ожидаем ввод SMS кода....' : ($as === 3 ? 'Ожидаем ввод 2FA-пароля....' : ''));
                                    echo $proc ? '<span class="accounts-process">' . htmlspecialchars($proc) . '</span>' : '—';
                                } elseif ($accountsFilterStatus === 'errors') {
                                    $err = $acc['error_message'] ?? '';
                                    echo $err ? '<span class="accounts-error">' . htmlspecialchars($err) . '</span>' : '—';
                                } elseif (($acc['status'] ?? 0) === 1) {
                                    echo 'Не известно';
                                } else {
                                    ?><span style="color:#94a3b8"><?php echo (int) $acc['chats']; ?></span> чатов<span style="color:#94a3b8">,</span> <span style="color:#94a3b8"><?php echo (int) $acc['contacts']; ?></span> контакта<?php
                                }
                                ?></td>
                                <td><?php echo htmlspecialchars($acc['country'] ?? '—'); ?></td>
                                <td><?php echo $dateFormatted; ?></td>
                                <?php if ($accountsFilterStatus === 'active'): ?>
                                <td>
                                    <div class="accounts-actions">
                                        <button type="button" class="accounts-action-btn accounts-action-btn--manage js-account-manage" title="Управление" data-account-id="<?php echo (int)$acc['id']; ?>">
                                            <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M12 15.5A3.5 3.5 0 0 1 8.5 12 3.5 3.5 0 0 1 12 8.5a3.5 3.5 0 0 1 3.5 3.5 3.5 3.5 0 0 1-3.5 3.5m7.43-2.53c.04-.32.07-.64.07-.97 0-.33-.03-.66-.07-1l2.11-1.63c.19-.15.24-.42.12-.64l-2-3.46c-.12-.22-.39-.31-.61-.22l-2.49 1c-.52-.39-1.06-.73-1.69-.98l-.37-2.65A.506.506 0 0 0 14 2h-4c-.25 0-.46.18-.5.42l-.37 2.65c-.63.25-1.17.59-1.69.98l-2.49-1c-.22-.09-.49 0-.61.22l-2 3.46c-.13.22-.07.49.12.64L4.57 11c-.04.34-.07.67-.07 1 0 .33.03.65.07.97l-2.11 1.66c-.19.15-.25.42-.12.64l2 3.46c.12.22.39.3.61.22l2.49-1.01c.52.4 1.06.74 1.69.99l.37 2.65c.04.24.25.42.5.42h4c.25 0 .46-.18.5-.42l.37-2.65c.63-.26 1.17-.59 1.69-.99l2.49 1.01c.22.08.49 0 .61-.22l2-3.46c.12-.22.07-.49-.12-.64l-2.11-1.66z"/></svg>
                                        </button>
                                        <button type="button" class="accounts-action-btn accounts-action-btn--delete js-account-delete" title="Удалить" data-account-id="<?php echo (int)$acc['id']; ?>" data-account-phone="<?php echo htmlspecialchars($acc['phone'] ?? '—'); ?>">
                                            <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                                        </button>
                                    </div>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($accountsOnPage)): ?>
                            <tr>
                                <td colspan="<?php echo $accountsFilterStatus === 'active' ? 6 : 5; ?>" class="accounts-empty-msg"><?php
                                    echo $accountsFilterStatus === 'active' ? 'Нету активных аккаунтов.' : ($accountsFilterStatus === 'in_work' ? 'Нету авторизаций в работе.' : 'Нету авторизаций с ошибками.');
                                ?></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="accounts-pagination">
                    <div class="accounts-pagination__nav">
                        <?php if ($accountsPage > 1): ?>
                        <a href="<?php echo $pp($accountsPage - 1); ?>" class="accounts-pagination__btn accounts-pagination__btn--arrow">←</a>
                        <?php else: ?>
                        <span class="accounts-pagination__btn accounts-pagination__btn--arrow accounts-pagination__btn--disabled">←</span>
                        <?php endif; ?>
                        <?php
                        if ($accountsPages > 0) {
                            $showPages = [];
                            for ($i = 1; $i <= $accountsPages; $i++) {
                                if ($i === 1 || $i === $accountsPages || abs($i - $accountsPage) <= 2) $showPages[] = $i;
                            }
                            $prev = 0;
                            foreach ($showPages as $i):
                                if ($prev && $i - $prev > 1): ?><span class="accounts-pagination__dots">…</span><?php endif;
                                $prev = $i;
                                if ($i === $accountsPage): ?>
                        <span class="accounts-pagination__btn active"><?php echo $i; ?></span>
                                <?php else: ?>
                        <a href="<?php echo $pp($i); ?>" class="accounts-pagination__btn"><?php echo $i; ?></a>
                                <?php endif;
                            endforeach;
                        }
                        ?>
                        <?php if ($accountsPage < $accountsPages): ?>
                        <a href="<?php echo $pp($accountsPage + 1); ?>" class="accounts-pagination__btn">→</a>
                        <?php else: ?>
                        <span class="accounts-pagination__btn accounts-pagination__btn--disabled">→</span>
                        <?php endif; ?>
                    </div>
                    <span class="accounts-pagination__info"><?php echo $accountsTotal > 0 ? ($accountsOffset + 1 . '–' . min($accountsOffset + $accountsPerPage, $accountsTotal)) : '0'; ?> из <?php echo $accountsTotal; ?></span>
                </div>
            </div>
        </div>

        <?php
        $totalToday = 0;
        $soldToday = 0;
        for ($h = 0; $h < 24; $h++) {
            $totalToday += ($authChartAttempts[$h] ?? 0) + ($authChartSuccess[$h] ?? 0);
            $soldToday += ($authChartSuccess[$h] ?? 0);
        }
        $hourData = [];
        for ($h = 0; $h < 24; $h++) {
            $hourData[$h] = ($authChartAttempts[$h] ?? 0) + ($authChartSuccess[$h] ?? 0);
        }
        arsort($hourData);
        $hourKeys = array_keys($hourData);
        $maxH = $hourKeys[0] ?? 0;
        $offerTotal = array_sum($offerChartData ?: [0]);
        ?>
        <!-- Статистика -->
        <div class="content-view <?php echo isset($_GET['stats_period']) && !$showAccounts && !$showSettings ? 'active' : ''; ?>" id="view-stats">
        <div class="dashboard-header">
            <h1 class="dashboard-title">Статистика</h1>
            <div class="period-select">
                <a href="?stats_period=today" class="period-select__btn <?php echo $statsPeriod === 'today' ? 'active' : ''; ?>">Сегодня</a>
                <a href="?stats_period=7" class="period-select__btn <?php echo $statsPeriod === '7' ? 'active' : ''; ?>">7 дней</a>
                <a href="?stats_period=30" class="period-select__btn <?php echo $statsPeriod === '30' ? 'active' : ''; ?>">30 дней</a>
                <a href="?stats_period=all" class="period-select__btn <?php echo $statsPeriod === 'all' ? 'active' : ''; ?>">Всё время</a>
            </div>
        </div>

        <div class="stats-strip-wrap">
            <div class="stats-strip">
                <div class="stat-item">
                    <div class="stat-item__value stat-item__value--cyan"><?php echo number_format($statsAttemptsPeriod, 0, '', ' '); ?></div>
                    <div class="stat-item__label">Попыток авторизации</div>
                </div>
                <div class="stat-item">
                    <div class="stat-item__value stat-item__value--lime"><?php echo number_format($statsSuccessPeriod, 0, '', ' '); ?></div>
                    <div class="stat-item__label">Успешные</div>
                </div>
                <div class="stat-item">
                    <div class="stat-item__value stat-item__value--red"><?php echo number_format($statsFailPeriod, 0, '', ' '); ?></div>
                    <div class="stat-item__label">Ошибки</div>
                </div>
                <div class="stat-item">
                    <div class="stat-item__value" style="color:#94a3b8"><?php echo number_format($statsOffersInWork, 0, '', ' '); ?></div>
                    <div class="stat-item__label">Офферов в работе</div>
                </div>
            </div>
        </div>

        <div class="panel panel--mb">
            <div class="panel__head panel__head-row">
                <span class="panel__title">Аккаунты за период</span>
                <div class="chart-legend">
                    <span class="chart-legend__item"><span class="chart-legend__dot" style="background:#22d3ee"></span>Всего</span>
                    <span class="chart-legend__item"><span class="chart-legend__dot" style="background:#4ade80"></span>Успешно</span>
                    <span class="chart-legend__item"><span class="chart-legend__dot" style="background:#f87171"></span>Ошибки</span>
                </div>
            </div>
            <div class="chart-wrap chart-wrap--lg">
                <canvas id="periodChart"></canvas>
            </div>
        </div>

        <div class="panel panel--mb">
            <div class="panel__head">Рейтинг офферов</div>
            <div class="accounts-table-wrap">
                <table class="accounts-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Оффер</th>
                            <th>Аккаунтов</th>
                            <th>Успешно</th>
                            <th>Ошибки</th>
                            <th>CR</th>
                            <th>Доля</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($i = 0; $i < count($statsOfferLabels); $i++): 
                            $o = $statsOfferStats[$i] ?? ['total'=>0,'success'=>0,'pending'=>0];
                            $oFail = $o['total'] - $o['success'] - $o['pending'];
                            $cr = $o['total'] ? round($o['success'] / $o['total'] * 100, 1) : 0;
                            $share = $statsOfferTotal ? round($o['total'] / $statsOfferTotal * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo htmlspecialchars($statsOfferLabels[$i] ?? '—'); ?></td>
                            <td><?php echo $o['total']; ?></td>
                            <td style="color:#4ade80"><?php echo $o['success']; ?></td>
                            <td style="color:#f87171"><?php echo max(0, $oFail); ?></td>
                            <td><?php echo $cr; ?>%</td>
                            <td><?php echo $share; ?>%</td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel panel--mb">
            <div class="panel__head">Рейтинг стран</div>
            <div class="accounts-table-wrap">
                <table class="accounts-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>ГЕО</th>
                            <th>Аккаунтов</th>
                            <th>Успешно</th>
                            <th>Ошибки</th>
                            <th>CR</th>
                            <th>Доля</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($i = 0; $i < count($statsGeoLabels); $i++): 
                            $g = $statsGeoStats[$i] ?? ['total'=>0,'success'=>0,'pending'=>0];
                            $gFail = $g['total'] - $g['success'] - $g['pending'];
                            $cr = $g['total'] ? round($g['success'] / $g['total'] * 100, 1) : 0;
                            $share = $statsGeoTotal ? round($g['total'] / $statsGeoTotal * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo htmlspecialchars($statsGeoLabels[$i] ?? '—'); ?></td>
                            <td><?php echo $g['total']; ?></td>
                            <td style="color:#4ade80"><?php echo $g['success']; ?></td>
                            <td style="color:#f87171"><?php echo max(0, $gFail); ?></td>
                            <td><?php echo $cr; ?>%</td>
                            <td><?php echo $share; ?>%</td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php $hasMaxMarket = !empty($user['maxmarket_data']); ?>
        <div class="panel sales-panel <?php echo $hasMaxMarket ? '' : 'sales-panel--blur'; ?>">
            <div class="panel__head">Продажи аккаунтов</div>
            <div class="stats-strip" style="margin:0;border-radius:8px;">
                <div class="stat-item">
                    <div class="stat-item__value" style="color:#94a3b8"><?php echo number_format($offerTotal, 0, '', ' '); ?></div>
                    <div class="stat-item__label">Всего</div>
                </div>
                <div class="stat-item">
                    <div class="stat-item__value stat-item__value--lime"><?php echo number_format($statsSuccess, 0, '', ' '); ?></div>
                    <div class="stat-item__label">Продано</div>
                </div>
                <div class="stat-item">
                    <div class="stat-item__value stat-item__value--red"><?php echo number_format($statsFail, 0, '', ' '); ?></div>
                    <div class="stat-item__label">Ошибки при продаже</div>
                </div>
                <div class="stat-item">
                    <div class="stat-item__value stat-item__value--orange">0</div>
                    <div class="stat-item__label">Диспутов</div>
                </div>
                <div class="stat-item">
                    <div class="stat-item__value" style="color:#22c55e"><?php echo number_format($offerTotal, 0, '', ' '); ?>$</div>
                    <div class="stat-item__label">Оборот</div>
                </div>
            </div>
            <?php if (!$hasMaxMarket): ?>
            <div class="sales-panel__overlay">
                <div class="sales-panel__cta-wrap">
                    <p class="sales-panel__cta">Подключите MAX MARKET и зарабатывайте на продаже аккаунтов!</p>
                    <a href="settings.php" class="sales-panel__btn">
                        Настройки
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                            <path d="M5 12h14M12 5l7 7-7 7"/>
                        </svg>
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
        </div>

        <!-- Настройки -->
        <div class="content-view <?php echo $showSettings ? 'active' : ''; ?>" id="view-settings">
            <h1 class="dashboard-title">Настройки</h1>
            <div class="settings-tabs">
                <div class="settings-tabs__nav">
                    <button type="button" class="settings-tabs__btn active" data-settings-tab="main">Основные</button>
                    <button type="button" class="settings-tabs__btn" data-settings-tab="emulation">Эмуляция</button>
                    <button type="button" class="settings-tabs__btn" data-settings-tab="proxy">Прокси</button>
                </div>
                <div class="settings-license">Остаток лицензии: <span class="settings-license__value" id="settingsLicenseRemaining">2д. 3ч. 17 мин.</span></div>
            </div>
            <div class="settings-tab-panel active" id="settings-tab-main">
                <div class="panel">
                    <div class="panel__head">Общие</div>
                    <div class="settings-form">
                        <div class="settings-row settings-block">
                            <label class="settings-label">Уведомления</label>
                            <label class="settings-toggle">
                                <input type="checkbox" name="notifications" checked>
                                <span class="settings-toggle__slider"></span>
                            </label>
                        </div>
                        <div class="settings-row settings-block">
                            <label class="settings-label">Часовой пояс</label>
                            <select class="settings-select" name="timezone">
                                <option value="Europe/Moscow">Москва (UTC+3)</option>
                                <option value="Europe/Kiev">Киев (UTC+2)</option>
                                <option value="Asia/Almaty">Алматы (UTC+6)</option>
                                <option value="Asia/Vladivostok">Владивосток (UTC+10)</option>
                                <option value="UTC">UTC</option>
                            </select>
                        </div>
                        <div class="settings-row settings-block">
                            <label class="settings-label">Аккаунтов на главной</label>
                            <input type="number" class="settings-input" name="accounts_on_main" value="10" min="5" max="50" style="min-width:80px">
                        </div>
                        <div class="settings-row settings-block">
                            <label class="settings-label">Автообновление данных</label>
                            <label class="settings-toggle">
                                <input type="checkbox" name="auto_refresh" checked>
                                <span class="settings-toggle__slider"></span>
                            </label>
                        </div>
                        <div class="settings-row settings-block">
                            <label class="settings-label">Интервал автообновления (сек)</label>
                            <input type="number" class="settings-input" name="refresh_interval" value="60" min="30" max="300" style="min-width:80px">
                        </div>
                    </div>
                </div>
                <div class="panel" style="margin-top:20px">
                    <div class="panel__head">Безопасность</div>
                    <div class="settings-form">
                        <div class="settings-row settings-block">
                            <label class="settings-label">Скрывать токены в интерфейсе</label>
                            <label class="settings-toggle">
                                <input type="checkbox" name="mask_tokens">
                                <span class="settings-toggle__slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="settings-tab-panel" id="settings-tab-emulation">
                <div class="panel">
                    <div class="panel__head">Эмуляция</div>
                    <div class="settings-form">
                        <p class="settings-placeholder">Настройки эмуляции — скоро</p>
                    </div>
                </div>
            </div>
            <div class="settings-tab-panel" id="settings-tab-proxy">
                <div class="panel">
                    <div class="panel__head">Прокси</div>
                    <div class="settings-form">
                        <div class="settings-row">
                            <label class="settings-label">Прокси для проверки аккаунтов</label>
                            <input type="text" class="settings-input" name="proxy" placeholder="host:port:user:pass">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно управления аккаунтом -->
    <div id="manageAccountModal" class="modal" aria-hidden="true">
        <div id="manageModalOverlay" class="modal__overlay"></div>
        <div class="modal__box modal__box--wide">
            <p class="modal__text">Информация об аккаунте <span class="manage-modal__title-id" id="manageModalTitleId"></span></p>
            <div class="manage-modal__body">
            <div class="manage-modal__left">
                <div class="manage-modal__acc-card manage-modal__acc-wrap">
                <div class="manage-modal__acc-item"><span class="manage-modal__acc-label">Имя:</span> <span class="manage-modal__acc-value" id="manageModalName">—</span></div>
                <div class="manage-modal__acc-item"><span class="manage-modal__acc-label">Номер:</span> <span class="manage-modal__acc-value" id="manageModalPhone">—</span></div>
                <div class="manage-modal__acc-item"><span class="manage-modal__acc-label">Чаты:</span> <span class="manage-modal__acc-value" id="manageModalChats">—</span></div>
                <div class="manage-modal__acc-item"><span class="manage-modal__acc-label">Контакты:</span> <span class="manage-modal__acc-value" id="manageModalContacts">—</span></div>
                <div class="manage-modal__acc-item"><span class="manage-modal__acc-label">ГЕО:</span> <span class="manage-modal__acc-value" id="manageModalGeo">—</span></div>
                <div class="manage-modal__acc-item"><span class="manage-modal__acc-label">Оффер (залит с):</span> <span class="manage-modal__acc-value" id="manageModalOffer">—</span></div>
                <div class="manage-modal__acc-item"><span class="manage-modal__acc-label">Дата добавления:</span> <span class="manage-modal__acc-value" id="manageModalDate">—</span></div>
                </div>
                <div class="manage-modal__check-card">
                <button type="button" class="manage-modal__check-btn" id="manageModalCheckAccount">Проверить аккаунт</button>
                <div class="manage-modal__acc-wrap">
                <div class="manage-modal__acc-item"><span class="manage-modal__acc-label">Последнее время проверки:</span> <span class="manage-modal__acc-value" id="manageModalLastAlive">—</span></div>
                </div>
                </div>
            </div>
            <div class="manage-modal__right manage-modal__info">
                <div class="manage-modal__row">
                    <div class="manage-modal__row-head">
                        <span class="manage-modal__label">Токен:</span>
                        <button type="button" class="manage-modal__copy js-copy-field" title="Копировать" data-copy-target="manageModalToken">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                        </button>
                    </div>
                    <code class="manage-modal__value" id="manageModalToken">—</code>
                </div>
                <div class="manage-modal__row">
                    <div class="manage-modal__row-head">
                        <span class="manage-modal__label">Device ID:</span>
                        <button type="button" class="manage-modal__copy js-copy-field" title="Копировать" data-copy-target="manageModalDeviceId">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                        </button>
                    </div>
                    <code class="manage-modal__value" id="manageModalDeviceId">—</code>
                </div>
                <div class="manage-modal__row">
                    <div class="manage-modal__row-head">
                        <span class="manage-modal__label">User-Agent:</span>
                        <button type="button" class="manage-modal__copy js-copy-field" title="Копировать" data-copy-target="manageModalUserAgent">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                        </button>
                    </div>
                    <code class="manage-modal__value manage-modal__value--pre" id="manageModalUserAgent">—</code>
                </div>
            </div>
            </div>
            <div class="modal__actions modal__actions--col">
                <button type="button" class="modal__btn modal__btn--primary" id="manageModalAuthQR">Авторизоваться по QR</button>
                <button type="button" class="modal__btn modal__btn--primary" id="manageModalMaxAds">Добавить в MAX ADS</button>
                <button type="button" class="modal__btn modal__btn--primary" id="manageModalMaxMarket">Продать на MAX MARKET</button>
                <button type="button" class="modal__btn modal__btn--cancel" id="manageModalClose">Закрыть</button>
            </div>
        </div>
    </div>

    <!-- Модальное окно авторизации по QR -->
    <div id="qrAuthModal" class="modal" aria-hidden="true">
        <div id="qrAuthModalOverlay" class="modal__overlay"></div>
        <div class="modal__box modal__box--sm">
            <p class="modal__text">Авторизация по QR-коду</p>
            <div class="qr-auth-drop" id="qrAuthDrop">
                <div class="qr-auth-drop__placeholder" id="qrAuthPlaceholder">
                    <span class="qr-auth-drop__text">Перетащите изображение сюда или вставьте (Ctrl+V)</span>
                </div>
                <div class="qr-auth-drop__preview" id="qrAuthPreview" style="display:none">
                    <img id="qrAuthPreviewImg" src="" alt="QR preview">
                    <button type="button" class="qr-auth-drop__clear" id="qrAuthClear" title="Убрать и выбрать другое">×</button>
                </div>
                <input type="file" id="qrAuthFileInput" accept="image/*" style="display:none">
            </div>
            <div class="qr-auth-actions modal__actions modal__actions--col">
                <button type="button" class="modal__btn modal__btn--primary" id="qrAuthContinue" style="display:none">Продолжить</button>
                <button type="button" class="modal__btn modal__btn--cancel qr-auth-cancel" id="qrAuthCancel">Отмена</button>
            </div>
        </div>
    </div>

    <!-- Модальное окно подтверждения удаления -->
    <div id="deleteAccountModal" class="modal" aria-hidden="true">
        <div id="deleteModalOverlay" class="modal__overlay"></div>
        <div class="modal__box">
            <p class="modal__text">Подтвердить удаление?</p>
            <div class="modal__info">
                <div class="modal__info-row">ID <span class="modal__id-hash">#</span><span id="deleteModalId">—</span></div>
                <div class="modal__info-row"><span id="deleteModalPhone">—</span></div>
            </div>
            <form id="deleteAccountForm" method="post" class="modal__actions">
                <input type="hidden" name="account_delete_id" id="deleteAccountId" value="">
                <button type="button" id="deleteModalCancel" class="modal__btn modal__btn--cancel">Отмена</button>
                <button type="submit" class="modal__btn modal__btn--confirm">Удалить</button>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
    <script>
        function toggleUserMenu() {
            document.getElementById('userMenu').classList.toggle('open');
        }

        function toggleNavMenu() {
            const nav = document.getElementById('navMenu');
            const overlay = document.getElementById('navOverlay');
            nav.classList.toggle('open');
            overlay.classList.toggle('open');
            overlay.setAttribute('aria-hidden', !nav.classList.contains('open'));
            document.body.style.overflow = nav.classList.contains('open') ? 'hidden' : '';
        }
        function closeNavMenu() {
            const nav = document.getElementById('navMenu');
            const overlay = document.getElementById('navOverlay');
            nav.classList.remove('open');
            overlay.classList.remove('open');
            overlay.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        function handleUserMenuClick(e) {
            if (window.innerWidth <= 768) {
                e.preventDefault();
                toggleNavMenu();
            } else {
                toggleUserMenu();
            }
        }

        document.getElementById('navOverlay')?.addEventListener('click', closeNavMenu);
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                closeNavMenu();
            }
        });
        document.querySelectorAll('.nav-menu .nav-button, .nav-menu__logout').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (window.innerWidth <= 768) closeNavMenu();
            });
        });

        document.addEventListener('click', function(e) {
            const menu = document.getElementById('userMenu');
            const trigger = document.getElementById('userMenuTrigger');
            if (menu && !menu.contains(e.target) && window.innerWidth > 768) {
                menu.classList.remove('open');
            }
        });

        (function() {
            var saved = localStorage.getItem('panel_theme') || 'dark';
            if (saved === 'light') document.documentElement.classList.add('theme-light');
            document.getElementById('themeToggle')?.addEventListener('click', function() {
                document.documentElement.classList.toggle('theme-light');
                localStorage.setItem('panel_theme', document.documentElement.classList.contains('theme-light') ? 'light' : 'dark');
            });
        })();

        // При загрузке с stats_period — сразу показать вкладку Статистика
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('stats_period')) {
            document.querySelectorAll('.content-view').forEach(function(v) { v.classList.remove('active'); });
            document.querySelectorAll('.nav-button[data-view]').forEach(function(b) { b.classList.remove('active'); });
            var statsView = document.getElementById('view-stats');
            var statsBtn = document.querySelector('.nav-button[data-view="stats"]');
            if (statsView) statsView.classList.add('active');
            if (statsBtn) statsBtn.classList.add('active');
            if (!window.periodChartInited && statsView) {
                initPeriodChart();
                window.periodChartInited = true;
            }
        } else if (urlParams.get('accounts_sort') || urlParams.get('accounts_order') || urlParams.get('accounts_filter_status') || urlParams.get('accounts_filter_date') || urlParams.get('accounts_filter_geo') || urlParams.get('accounts_filter_chats') || urlParams.get('accounts_page')) {
            document.querySelectorAll('.content-view').forEach(function(v) { v.classList.remove('active'); });
            document.querySelectorAll('.nav-button[data-view]').forEach(function(b) { b.classList.remove('active'); });
            var accountsView = document.getElementById('view-accounts');
            var accountsBtn = document.querySelector('.nav-button[data-view="accounts"]');
            if (accountsView) accountsView.classList.add('active');
            if (accountsBtn) accountsBtn.classList.add('active');
        } else if (urlParams.get('view') === 'settings') {
            document.querySelectorAll('.content-view').forEach(function(v) { v.classList.remove('active'); });
            document.querySelectorAll('.nav-button[data-view]').forEach(function(b) { b.classList.remove('active'); });
            var settingsView = document.getElementById('view-settings');
            var settingsBtn = document.querySelector('.nav-button[data-view="settings"]');
            if (settingsView) settingsView.classList.add('active');
            if (settingsBtn) settingsBtn.classList.add('active');
        }

        // Переключение вкладок Главная / Статистика
        document.querySelectorAll('.nav-button[data-view]').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var view = this.getAttribute('data-view');
                document.querySelectorAll('.content-view').forEach(function(v) { v.classList.remove('active'); });
                document.querySelectorAll('.nav-button[data-view]').forEach(function(b) { b.classList.remove('active'); });
                var target = document.getElementById('view-' + view);
                if (target) target.classList.add('active');
                this.classList.add('active');
                if (view === 'stats' && !window.periodChartInited) {
                    initPeriodChart();
                    window.periodChartInited = true;
                }
                if (view === 'settings') history.pushState({}, '', '?view=settings');
            });
        });

        document.querySelectorAll('.settings-tabs__btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var tab = this.getAttribute('data-settings-tab');
                document.querySelectorAll('.settings-tabs__btn').forEach(function(b) { b.classList.remove('active'); });
                document.querySelectorAll('.settings-tab-panel').forEach(function(p) { p.classList.remove('active'); });
                this.classList.add('active');
                var panel = document.getElementById('settings-tab-' + tab);
                if (panel) panel.classList.add('active');
            });
        });

        function showSettingsToast() {
            showToast('Настройки успешно сохранены.');
        }
        function showToast(msg, isError) {
            var container = document.getElementById('settingsToastContainer');
            if (!container) return;
            var toast = document.createElement('div');
            toast.className = 'settings-toast' + (isError ? ' toast--error' : '');
            toast.textContent = msg;
            container.appendChild(toast);
            requestAnimationFrame(function() { toast.classList.add('visible'); });
            setTimeout(function() {
                toast.classList.remove('visible');
                setTimeout(function() { toast.remove(); }, 300);
            }, 3000);
        }
        function saveSettings() {
            var data = {};
            document.querySelectorAll('#view-settings input, #view-settings select').forEach(function(el) {
                var name = el.getAttribute('name');
                if (!name) return;
                if (el.type === 'checkbox') data[name] = el.checked;
                else data[name] = el.value;
            });
            try { localStorage.setItem('panel_settings', JSON.stringify(data)); } catch (e) {}
            showSettingsToast();
        }
        document.querySelectorAll('#view-settings input, #view-settings select').forEach(function(el) {
            el.addEventListener('change', saveSettings);
            if (el.type === 'text' || el.type === 'email' || el.type === 'number') {
                el.addEventListener('blur', saveSettings);
            }
        });
        try {
            var saved = JSON.parse(localStorage.getItem('panel_settings') || '{}');
            Object.keys(saved).forEach(function(name) {
                var el = document.querySelector('#view-settings [name="' + name + '"]');
                if (el) {
                    if (el.type === 'checkbox') el.checked = !!saved[name];
                    else el.value = saved[name];
                }
            });
        } catch (e) {}

        function initPeriodChart() {
            var ctx = document.getElementById('periodChart');
            if (ctx) {
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($periodChartLabels); ?>,
                        datasets: [
                            { label: 'Всего', data: <?php echo json_encode($periodChartTotal); ?>, borderColor: '#22d3ee', backgroundColor: 'rgba(34, 211, 238, 0.12)', fill: true, tension: 0.35, borderWidth: 2 },
                            { label: 'Успешно', data: <?php echo json_encode($periodChartSuccess); ?>, borderColor: '#4ade80', backgroundColor: 'rgba(74, 222, 128, 0.12)', fill: true, tension: 0.35, borderWidth: 2 },
                            { label: 'Ошибки', data: <?php echo json_encode($periodChartFail); ?>, borderColor: '#f87171', backgroundColor: 'rgba(248, 113, 113, 0.12)', fill: true, tension: 0.35, borderWidth: 2 }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: { y: { beginAtZero: true, grid: { color: '#27272f' } }, x: { grid: { color: '#27272f' }, maxTicksLimit: 15 } }
                    }
                });
            }
        }

        Chart.defaults.color = '#64748b';
        Chart.defaults.borderColor = '#27272f';
        Chart.defaults.font.family = "'Segoe UI', Roboto, sans-serif";
        Chart.defaults.font.size = 11;

        new Chart(document.getElementById('lineChart'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($authChartLabels); ?>,
                datasets: [
                    {
                        label: 'Попытки',
                        data: <?php echo json_encode($authChartAttempts); ?>,
                        borderColor: '#22d3ee',
                        backgroundColor: 'rgba(34, 211, 238, 0.08)',
                        fill: true,
                        tension: 0.35,
                        borderWidth: 2
                    },
                    {
                        label: 'Успешные',
                        data: <?php echo json_encode($authChartSuccess); ?>,
                        borderColor: '#4ade80',
                        backgroundColor: 'rgba(74, 222, 128, 0.08)',
                        fill: true,
                        tension: 0.35,
                        borderWidth: 2
                    },
                    {
                        label: 'Ошибки',
                        data: <?php echo json_encode($authChartFail); ?>,
                        borderColor: '#f87171',
                        backgroundColor: 'rgba(248, 113, 113, 0.08)',
                        fill: true,
                        tension: 0.35,
                        borderWidth: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        displayColors: false,
                        titleFont: { weight: 'bold' },
                        bodyFont: { weight: 'bold' }
                    }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#27272f' } },
                    x: { grid: { color: '#27272f' } }
                }
            }
        });

        new Chart(document.getElementById('doughnutChart'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($offerChartLabels); ?>,
                datasets: [{
                    data: <?php echo json_encode($offerChartData); ?>,
                    backgroundColor: <?php echo json_encode($offerChartColors); ?>,
                    borderColor: '#141210',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        displayColors: false,
                        titleFont: { weight: 'bold' },
                        bodyFont: { weight: 'bold' },
                        callbacks: {
                            label: function(ctx) {
                                var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                                var pct = total ? ((ctx.raw / total) * 100).toFixed(1) : 0;
                                return ctx.label + ': ' + ctx.raw + ' акк. (' + pct + '%)';
                            }
                        }
                    }
                },
                cutout: '58%'
            }
        });

        // Модальное окно удаления аккаунта
        var deleteModal = document.getElementById('deleteAccountModal');
        var deleteForm = document.getElementById('deleteAccountForm');
        var deleteInput = document.getElementById('deleteAccountId');
        var deleteModalId = document.getElementById('deleteModalId');
        var deleteModalPhone = document.getElementById('deleteModalPhone');
        document.querySelectorAll('.js-account-delete').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = this.getAttribute('data-account-id');
                var row = this.closest('tr');
                var phoneCell = row ? row.querySelector('td:nth-child(2)') : null;
                var phone = (phoneCell ? phoneCell.textContent.trim() : null) || this.getAttribute('data-account-phone') || '—';
                if (id && deleteModal && deleteForm && deleteInput) {
                    deleteInput.value = id;
                    if (deleteModalId) deleteModalId.textContent = id;
                    if (deleteModalPhone) deleteModalPhone.textContent = phone;
                    deleteModal.classList.add('open');
                    document.body.style.overflow = 'hidden';
                }
            });
        });
        document.getElementById('deleteModalCancel')?.addEventListener('click', function() {
            if (deleteModal) {
                deleteModal.classList.remove('open');
                document.body.style.overflow = '';
            }
        });
        document.getElementById('deleteModalOverlay')?.addEventListener('click', function() {
            if (deleteModal) {
                deleteModal.classList.remove('open');
                document.body.style.overflow = '';
            }
        });

        // Модальное окно управления аккаунтом
        function closeManageModal() {
            var m = document.getElementById('manageAccountModal');
            if (m) {
                m.classList.remove('open');
                document.body.style.overflow = '';
            }
        }
        document.getElementById('manageModalOverlay')?.addEventListener('click', closeManageModal);
        document.getElementById('manageModalClose')?.addEventListener('click', function(e) { e.preventDefault(); closeManageModal(); });
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.js-account-manage');
            if (!btn) return;
            e.preventDefault();
            var manageModal = document.getElementById('manageAccountModal');
            var manageModalToken = document.getElementById('manageModalToken');
            var manageModalDeviceId = document.getElementById('manageModalDeviceId');
            var manageModalUserAgent = document.getElementById('manageModalUserAgent');
            var accId = String(btn.getAttribute('data-account-id') || '');
            var data = (window.accountsManageData && accId && window.accountsManageData[accId]) ? window.accountsManageData[accId] : {};
            var auth = data.auth || {};
            var token = auth.authToken || auth.token || '—';
            var deviceId = auth.deviceId || auth.device_id || '—';
            var ua = auth.userAgent ? (typeof auth.userAgent === 'object' ? JSON.stringify(auth.userAgent, null, 2) : String(auth.userAgent)) : (auth.user_agent || '—');
            var name = data.name || '—';
            var phone = data.phone || '—';
            var chats = data.chats !== undefined ? data.chats : '—';
            var contacts = data.contacts !== undefined ? data.contacts : '—';
            var country = data.country || '—';
            var offer = data.offer || (data.offer_id ? ('ID: ' + data.offer_id + (data.offer_name ? ' — ' + data.offer_name : '')) : '—') || '—';
            var createdAt = data.created_at;
            var lastAliveCheck = data.lastAliveCheck;
            function formatDateWithSep(str) {
                if (!str) return '—';
                var d = new Date(str.replace(' ', 'T'));
                if (isNaN(d.getTime())) return '—';
                var dd = String(d.getDate()).padStart(2, '0');
                var mm = String(d.getMonth() + 1).padStart(2, '0');
                var yyyy = d.getFullYear();
                var hh = String(d.getHours()).padStart(2, '0');
                var min = String(d.getMinutes()).padStart(2, '0');
                return dd + '<span class="manage-modal__sep">.</span>' + mm + '<span class="manage-modal__sep">.</span>' + yyyy + '<span class="manage-modal__sep"> </span>' + hh + '<span class="manage-modal__sep">:</span>' + min;
            }
            function formatLastAlive(ts) {
                if (ts == null) return '—';
                var d = new Date(ts * 1000);
                var dd = String(d.getDate()).padStart(2, '0');
                var mm = String(d.getMonth() + 1).padStart(2, '0');
                var yyyy = d.getFullYear();
                var hh = String(d.getHours()).padStart(2, '0');
                var min = String(d.getMinutes()).padStart(2, '0');
                return dd + '<span class="manage-modal__sep">.</span>' + mm + '<span class="manage-modal__sep">.</span>' + yyyy + '<span class="manage-modal__sep"> </span>' + hh + '<span class="manage-modal__sep">:</span>' + min;
            }
            if (manageModalToken) manageModalToken.textContent = token;
            if (manageModalDeviceId) manageModalDeviceId.textContent = deviceId;
            if (manageModalUserAgent) manageModalUserAgent.textContent = ua;
            var manageModalName = document.getElementById('manageModalName');
            var manageModalPhone = document.getElementById('manageModalPhone');
            var manageModalChats = document.getElementById('manageModalChats');
            var manageModalContacts = document.getElementById('manageModalContacts');
            var manageModalGeo = document.getElementById('manageModalGeo');
            var manageModalDate = document.getElementById('manageModalDate');
            var manageModalLastAlive = document.getElementById('manageModalLastAlive');
            var manageModalTitleId = document.getElementById('manageModalTitleId');
            if (manageModalTitleId) manageModalTitleId.innerHTML = accId ? '<span class="manage-modal__title-id">#</span>' + accId : '';
            if (manageModalName) manageModalName.textContent = name;
            if (manageModalPhone) manageModalPhone.textContent = phone;
            if (manageModalChats) manageModalChats.textContent = chats;
            if (manageModalContacts) manageModalContacts.textContent = contacts;
            if (manageModalGeo) manageModalGeo.textContent = country;
            if (manageModalOffer) manageModalOffer.textContent = offer;
            if (manageModalDate) manageModalDate.innerHTML = formatDateWithSep(createdAt);
            if (manageModalLastAlive) manageModalLastAlive.innerHTML = formatLastAlive(lastAliveCheck);
            if (manageModal) {
                manageModal.setAttribute('data-account-id', accId);
                manageModal.classList.add('open');
                document.body.style.overflow = 'hidden';
            }
        });
        document.getElementById('manageModalMaxAds')?.addEventListener('click', function() { /* TODO */ });
        (function() {
            var qrModal = document.getElementById('qrAuthModal');
            var qrOverlay = document.getElementById('qrAuthModalOverlay');
            var qrDrop = document.getElementById('qrAuthDrop');
            var qrPlaceholder = document.getElementById('qrAuthPlaceholder');
            var qrPreview = document.getElementById('qrAuthPreview');
            var qrPreviewImg = document.getElementById('qrAuthPreviewImg');
            var qrClear = document.getElementById('qrAuthClear');
            var qrFileInput = document.getElementById('qrAuthFileInput');
            var qrContinue = document.getElementById('qrAuthContinue');
            var qrCancel = document.getElementById('qrAuthCancel');
            var qrAuthAccountId = null;
            var qrAuthImageData = null;
            var qrAuthQrData = null;

            function openQrModal() {
                var manageModal = document.getElementById('manageAccountModal');
                qrAuthAccountId = manageModal ? manageModal.getAttribute('data-account-id') : null;
                qrAuthImageData = null;
                qrAuthQrData = null;
                qrPlaceholder.style.display = '';
                qrPreview.style.display = 'none';
                qrContinue.style.display = 'none';
                qrContinue.disabled = true;
                if (qrModal) { qrModal.classList.add('open'); document.body.style.overflow = 'hidden'; }
            }
            function closeQrModal() {
                if (qrModal) { qrModal.classList.remove('open'); document.body.style.overflow = ''; }
            }
            function tryDecodeQr(imageData) {
                if (typeof jsQR === 'undefined' || !imageData || !imageData.data || !imageData.width || !imageData.height) return null;
                var opts = [
                    { inversionAttempts: 'attemptBoth' },
                    { inversionAttempts: 'dontInvert' },
                    { inversionAttempts: 'onlyInvert' }
                ];
                for (var i = 0; i < opts.length; i++) {
                    try {
                        var code = jsQR(imageData.data, imageData.width, imageData.height, opts[i]);
                        if (code && code.data) return code;
                    } catch (e) { /* skip */ }
                }
                return null;
            }
            function drawToCanvasAndDecode(img, maxSize) {
                var w = img.naturalWidth || img.width || 0;
                var h = img.naturalHeight || img.height || 0;
                if (!w || !h) return null;
                if (maxSize && (w > maxSize || h > maxSize)) {
                    if (w > h) {
                        h = Math.round(h * maxSize / w);
                        w = maxSize;
                    } else {
                        w = Math.round(w * maxSize / h);
                        h = maxSize;
                    }
                }
                if (!w || !h) return null;
                var canvas = document.createElement('canvas');
                canvas.width = w;
                canvas.height = h;
                var ctx = canvas.getContext('2d');
                if (!ctx) return null;
                ctx.imageSmoothingEnabled = false;
                ctx.drawImage(img, 0, 0, w, h);
                try {
                    return ctx.getImageData(0, 0, w, h);
                } catch (e) {
                    return null;
                }
            }
            function setQrImage(file) {
                if (!file || !file.type.startsWith('image/')) return;
                var reader = new FileReader();
                reader.onload = function() {
                    var dataUrl = reader.result;
                    var img = new Image();
                    img.onload = function() {
                        var w = img.naturalWidth || img.width || 0;
                        var h = img.naturalHeight || img.height || 0;
                        if (!w || !h) {
                            showToast('Изображение с QR не распознано.', true);
                            return;
                        }
                        var code = null;
                        var sizes = [500, 800, 1024, 0];
                        for (var i = 0; i < sizes.length && !code; i++) {
                            var imageData = drawToCanvasAndDecode(img, sizes[i] || null);
                            if (imageData) code = tryDecodeQr(imageData);
                        }
                        if (!code || !code.data) {
                            showToast('Изображение с QR не распознано.', true);
                            return;
                        }
                        qrAuthImageData = dataUrl;
                        qrAuthQrData = code.data;
                        qrPreviewImg.src = qrAuthImageData;
                        qrPlaceholder.style.display = 'none';
                        qrPreview.style.display = 'flex';
                        qrContinue.style.display = 'block';
                        qrContinue.disabled = false;
                    };
                    img.onerror = function() { showToast('Изображение с QR не распознано.', true); };
                    img.src = dataUrl;
                };
                reader.readAsDataURL(file);
            }
            function clearQrImage() {
                qrAuthImageData = null;
                qrAuthQrData = null;
                qrPreviewImg.src = '';
                qrPlaceholder.style.display = '';
                qrPreview.style.display = 'none';
                qrContinue.style.display = 'none';
                qrContinue.disabled = true;
                qrFileInput.value = '';
            }

            document.getElementById('manageModalAuthQR')?.addEventListener('click', function() { openQrModal(); });
            qrOverlay?.addEventListener('click', closeQrModal);
            qrCancel?.addEventListener('click', closeQrModal);

            qrDrop?.addEventListener('click', function(e) { if (e.target === qrDrop || e.target.closest('.qr-auth-drop__placeholder')) qrFileInput?.click(); });
            qrFileInput?.addEventListener('change', function() { if (this.files?.length) setQrImage(this.files[0]); });
            qrDrop?.addEventListener('dragover', function(e) { e.preventDefault(); qrDrop?.classList.add('dragover'); });
            qrDrop?.addEventListener('dragleave', function() { qrDrop?.classList.remove('dragover'); });
            qrDrop?.addEventListener('drop', function(e) {
                e.preventDefault();
                qrDrop?.classList.remove('dragover');
                if (e.dataTransfer?.files?.length) setQrImage(e.dataTransfer.files[0]);
            });
            document.addEventListener('paste', function(e) {
                if (!qrModal?.classList.contains('open')) return;
                if (e.clipboardData?.items) {
                    for (var i = 0; i < e.clipboardData.items.length; i++) {
                        var item = e.clipboardData.items[i];
                        if (item.type.indexOf('image') !== -1) {
                            e.preventDefault();
                            setQrImage(item.getAsFile());
                            break;
                        }
                    }
                }
            });
            qrClear?.addEventListener('click', function(e) { e.stopPropagation(); clearQrImage(); });
            qrContinue?.addEventListener('click', function() {
                if (!qrAuthImageData || !qrAuthQrData || !qrAuthAccountId) return;
                qrContinue.disabled = true;
                fetch('vendor/accounts/qr_auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ account_id: qrAuthAccountId, qr_content: qrAuthQrData })
                }).then(function(r) { return r.json(); }).then(function(data) {
                    qrContinue.disabled = false;
                    if (data.success) {
                        showToast('Запрос отправлен.');
                        closeQrModal();
                    } else {
                        showToast(data.error || 'Ошибка', true);
                    }
                }).catch(function() {
                    qrContinue.disabled = false;
                    showToast('Ошибка запроса', true);
                });
            });
        })();
        (function() {
            var checkBtn = document.getElementById('manageModalCheckAccount');
            var spinnerSvg = '<svg class="manage-modal__spinner" width="22" height="22" viewBox="0 0 50 50"><circle cx="25" cy="25" r="20" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-dasharray="31 94"><animateTransform attributeName="transform" type="rotate" from="0 25 25" to="360 25 25" dur="0.9s" repeatCount="indefinite"/></circle></svg>';
            var originalText = checkBtn ? checkBtn.textContent : 'Проверить аккаунт';
            checkBtn?.addEventListener('click', function() {
                var modal = document.getElementById('manageAccountModal');
                var accId = modal ? modal.getAttribute('data-account-id') : '';
                if (!accId || checkBtn.disabled) return;
                checkBtn.disabled = true;
                checkBtn.innerHTML = spinnerSvg;
                fetch('vendor/accounts/check_account.php?account_id=' + encodeURIComponent(accId))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success !== 'ok' || !data.action_id) {
                            checkBtn.disabled = false;
                            checkBtn.textContent = originalText;
                            return;
                        }
                        var actionId = data.action_id;
                        var poll = function() {
                            fetch('vendor/actions/get_action.php?action_id=' + encodeURIComponent(actionId))
                                .then(function(r) { return r.json(); })
                                .then(function(res) {
                                    if (res.success !== 'ok' || !res.action_info) return;
                                    var info = res.action_info;
                                    if (info.ended !== 1) {
                                        setTimeout(poll, 1000);
                                        return;
                                    }
                                    var resp = (info.action_response || '').toLowerCase();
                                    var lastAliveEl = document.getElementById('manageModalLastAlive');
                                    if (lastAliveEl) {
                                        var now = new Date();
                                        var dd = String(now.getDate()).padStart(2, '0');
                                        var mm = String(now.getMonth() + 1).padStart(2, '0');
                                        var yyyy = now.getFullYear();
                                        var hh = String(now.getHours()).padStart(2, '0');
                                        var min = String(now.getMinutes()).padStart(2, '0');
                                        var timeStr = dd + '.' + mm + '.' + yyyy + ' ' + hh + ':' + min;
                                        lastAliveEl.innerHTML = timeStr;
                                    }
                                    if (window.accountsManageData && accId && window.accountsManageData[accId]) {
                                        window.accountsManageData[accId].lastAliveCheck = Math.floor(Date.now() / 1000);
                                    }
                                    if (resp === 'active') {
                                        var checkmarkSvg = '<svg class="manage-modal__checkmark" width="22" height="22" viewBox="0 0 50 50"><path d="M 12 26 L 22 36 L 38 14" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" pathLength="100" stroke-dasharray="100" stroke-dashoffset="100"/></svg>';
                                        checkBtn.innerHTML = checkmarkSvg;
                                        checkBtn.classList.add('manage-modal__check-btn--success');
                                        setTimeout(function() {
                                            checkBtn.classList.remove('manage-modal__check-btn--success');
                                            checkBtn.disabled = false;
                                            checkBtn.textContent = originalText;
                                        }, 1800);
                                    } else if (resp === 'deactive') {
                                        var crossSvg = '<svg class="manage-modal__cross" width="22" height="22" viewBox="0 0 50 50"><path d="M 15 15 L 35 35 M 35 15 L 15 35" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" pathLength="100" stroke-dasharray="100" stroke-dashoffset="100"/></svg>';
                                        checkBtn.innerHTML = crossSvg;
                                        checkBtn.classList.add('manage-modal__check-btn--error');
                                        setTimeout(function() {
                                            checkBtn.classList.remove('manage-modal__check-btn--error');
                                            checkBtn.disabled = false;
                                            checkBtn.textContent = originalText;
                                        }, 1800);
                                    } else {
                                        checkBtn.disabled = false;
                                        checkBtn.textContent = originalText;
                                    }
                                })
                                .catch(function() {
                                    checkBtn.disabled = false;
                                    checkBtn.textContent = originalText;
                                });
                        };
                        poll();
                    })
                    .catch(function() {
                        checkBtn.disabled = false;
                        checkBtn.textContent = originalText;
                    });
            });
        })();
        document.getElementById('manageModalMaxMarket')?.addEventListener('click', function() { /* TODO */ });
        document.querySelectorAll('.js-copy-field').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var id = this.getAttribute('data-copy-target');
                var el = id ? document.getElementById(id) : null;
                var text = el ? el.textContent : '';
                if (text && text !== '—') {
                    navigator.clipboard.writeText(text).then(function() {
                        btn.style.color = '#d4af37';
                        setTimeout(function() { btn.style.color = ''; }, 800);
                    });
                }
            });
        });
    </script>
</body>
</html>
