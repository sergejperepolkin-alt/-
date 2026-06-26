<?php
function pdo_connect() {
    try {
        $host = 'artemkk5.beget.tech';
        $dbname = 'artemkk5_max';
        $username = 'artemkk5_max';
        $password = '54b3L2A7!';
        
        $pdo = new PDO(
            "mysql:host=$host;port=3306;dbname=$dbname;charset=utf8mb4",
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
        http_response_code(500);
        die(json_encode(['success' => false, 'error' => 'Database connection failed']));
    }
}
?>
