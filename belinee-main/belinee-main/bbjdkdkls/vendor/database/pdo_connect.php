<?php
/**
 * PDO подключение к БД - с поддержкой Railway
 */
function pdo_connect() {
    // Для Railway используем MYSQL_URL
    $mysqlUrl = getenv('MYSQL_URL');
    
    if ($mysqlUrl) {
        // Парсим MYSQL_URL формата: mysql://user:password@host:port/database
        $url = parse_url($mysqlUrl);
        $host = $url['host'] ?? 'localhost';
        $user = $url['user'] ?? 'root';
        $password = $url['pass'] ?? '';
        $database = ltrim($url['path'] ?? '/railway', '/');
        $port = $url['port'] ?? 3306;
    } else {
        // Fallback для локальной разработки
        $host = getenv('DB_HOST') ?: getenv('MYSQLHOST') ?: 'localhost';
        $user = getenv('DB_USER') ?: getenv('MYSQLUSER') ?: 'root';
        $password = getenv('DB_PASSWORD') ?: getenv('MYSQLPASSWORD') ?: '';
        $database = getenv('DB_NAME') ?: getenv('MYSQL_DATABASE') ?: 'invader_panel';
        $port = getenv('DB_PORT') ?: getenv('MYSQLPORT') ?: 3306;
    }

    try {
        $pdo = new PDO(
            "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]));
    }
}
?>