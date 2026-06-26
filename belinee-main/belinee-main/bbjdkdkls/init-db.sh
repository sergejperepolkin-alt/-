#!/bin/bash
# Инициализация БД при деплое на Railway

echo "🚀 Инициализация базы данных..."

# Проверяем переменные окружения
if [ -z "$DB_HOST" ] || [ -z "$DB_USER" ] || [ -z "$DB_NAME" ]; then
    echo "❌ Не установлены переменные окружения БД"
    exit 1
fi

# Создаём БД и таблицы через PHP
php -r "
    \$host = getenv('DB_HOST');
    \$user = getenv('DB_USER');
    \$password = getenv('DB_PASSWORD');
    \$dbName = getenv('DB_NAME');
    \$port = getenv('DB_PORT') ?: 3306;

    try {
        \$pdo = new PDO(
            \"mysql:host=\$host;port=\$port;charset=utf8mb4\",
            \$user,
            \$password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        \$pdo->exec(\"CREATE DATABASE IF NOT EXISTS \\`\$dbName\\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci\");
        
        \$pdo = new PDO(
            \"mysql:host=\$host;port=\$port;dbname=\$dbName;charset=utf8mb4\",
            \$user,
            \$password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        \$pdo->exec(\"\n            CREATE TABLE IF NOT EXISTS \\`users\\` (
                \\`id\\` INT AUTO_INCREMENT PRIMARY KEY,
                \\`login\\` VARCHAR(64) UNIQUE NOT NULL,
                \\`email\\` VARCHAR(255) UNIQUE,
                \\`password\\` VARCHAR(255) NOT NULL,
                \\`status\\` TINYINT DEFAULT 1,
                \\`created_at\\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                \\`updated_at\\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (\\`login\\`),
                INDEX (\\`status\\`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        \");

        \$pdo->exec(\"\n            CREATE TABLE IF NOT EXISTS \\`users_sessions\\` (
                \\`id\\` INT AUTO_INCREMENT PRIMARY KEY,
                \\`user_id\\` INT NOT NULL,
                \\`token\\` VARCHAR(255) UNIQUE NOT NULL,
                \\`status\\` TINYINT DEFAULT 1,
                \\`expires_at\\` TIMESTAMP NULL,
                \\`created_at\\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                \\`updated_at\\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (\\`user_id\\`) REFERENCES \\`users\\`(\\`id\\`) ON DELETE CASCADE,
                INDEX (\\`token\\`),
                INDEX (\\`user_id\\`),
                INDEX (\\`status\\`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        \");

        \$pdo->exec(\"\n            CREATE TABLE IF NOT EXISTS \\`accounts\\` (
                \\`id\\` INT AUTO_INCREMENT PRIMARY KEY,
                \\`login\\` VARCHAR(255),
                \\`auth_data\\` JSON,
                \\`account_data\\` JSON,
                \\`status\\` INT DEFAULT 1,
                \\`created_at\\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                \\`updated_at\\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (\\`status\\`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        \");

        \$pdo->exec(\"\n            CREATE TABLE IF NOT EXISTS \\`actions\\` (
                \\`id\\` INT AUTO_INCREMENT PRIMARY KEY,
                \\`action_id\\` INT UNIQUE NOT NULL,
                \\`account_id\\` INT,
                \\`ended\\` TINYINT DEFAULT 0,
                \\`action_response\\` TEXT,
                \\`created_at\\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (\\`account_id\\`),
                INDEX (\\`action_id\\`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        \");

        echo \"✅✅✅ База данных инициализирована успешно!\\n\";
    } catch (Exception \$e) {
        echo \"❌ Ошибка: \" . \$e->getMessage() . \"\\n\";
        exit(1);
    }
"

echo "✅ Инициализация завершена"
