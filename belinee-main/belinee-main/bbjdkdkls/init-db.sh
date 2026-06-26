#!/bin/bash
# Инициализация БД при деплое на Railway

echo "🚀 Инициализация базы данных Railway..."

# Используем MYSQL_URL из Railway или отдельные переменные
if [ -n "$MYSQL_URL" ]; then
    echo "✅ Найдена MYSQL_URL переменная"
else
    echo "✅ Используем отдельные переменные (MYSQLHOST, MYSQLUSER, и т.д.)"
fi

# Определяем хост для подключения
if [ -n "$MYSQL_URL" ]; then
    DB_HOST=$(echo $MYSQL_URL | sed 's|mysql://[^@]*@\([^:/]*\).*|\1|')
    DB_USER=$(echo $MYSQL_URL | sed 's|mysql://\([^:]*\).*|\1|')
    DB_PASSWORD=$(echo $MYSQL_URL | sed 's|mysql://[^:]*:\([^@]*\)@.*|\1|')
    DB_NAME=$(echo $MYSQL_URL | sed 's|.*\/\([^/]*\)$|\1|')
    DB_PORT=$(echo $MYSQL_URL | sed 's|.*:\([0-9]*\)\/.*|\1|; s|[^0-9]||g')
    [ -z "$DB_PORT" ] && DB_PORT=3306
else
    DB_HOST=${MYSQLHOST:-localhost}
    DB_USER=${MYSQLUSER:-root}
    DB_PASSWORD=${MYSQLPASSWORD:-}
    DB_NAME=${MYSQL_DATABASE:-railway}
    DB_PORT=${MYSQLPORT:-3306}
fi

echo "📝 Подключение к: $DB_HOST:$DB_PORT/$DB_NAME"

# Создаём БД и таблицы через PHP
php -r "
    \$host = '$DB_HOST';
    \$user = '$DB_USER';
    \$password = '$DB_PASSWORD';
    \$dbName = '$DB_NAME';
    \$port = '$DB_PORT';

    try {
        \$pdo = new PDO(
            \"mysql:host=\$host;port=\$port;charset=utf8mb4\",
            \$user,
            \$password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Создаём БД если её нет
        \$pdo->exec(\"CREATE DATABASE IF NOT EXISTS \\`\$dbName\\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci\");
        echo \"✅ БД \$dbName готова\\n\";
        
        // Подключаемся к нужной БД
        \$pdo = new PDO(
            \"mysql:host=\$host;port=\$port;dbname=\$dbName;charset=utf8mb4\",
            \$user,
            \$password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Таблица users
        \$pdo->exec(\"CREATE TABLE IF NOT EXISTS \\`users\\` (
            \\`id\\` INT AUTO_INCREMENT PRIMARY KEY,
            \\`login\\` VARCHAR(64) UNIQUE NOT NULL,
            \\`email\\` VARCHAR(255) UNIQUE,
            \\`password\\` VARCHAR(255) NOT NULL,
            \\`status\\` TINYINT DEFAULT 1,
            \\`created_at\\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            \\`updated_at\\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (\\`login\\`),
            INDEX (\\`status\\`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\");
        echo \"✅ Таблица users создана\\n\";

        // Таблица users_sessions
        \$pdo->exec(\"CREATE TABLE IF NOT EXISTS \\`users_sessions\\` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\");
        echo \"✅ Таблица users_sessions создана\\n\";

        // Таблица accounts
        \$pdo->exec(\"CREATE TABLE IF NOT EXISTS \\`accounts\\` (
            \\`id\\` INT AUTO_INCREMENT PRIMARY KEY,
            \\`login\\` VARCHAR(255),
            \\`auth_data\\` JSON,
            \\`account_data\\` JSON,
            \\`status\\` INT DEFAULT 1,
            \\`created_at\\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            \\`updated_at\\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (\\`status\\`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\");
        echo \"✅ Таблица accounts создана\\n\";

        // Таблица actions
        \$pdo->exec(\"CREATE TABLE IF NOT EXISTS \\`actions\\` (
            \\`id\\` INT AUTO_INCREMENT PRIMARY KEY,
            \\`action_id\\` INT UNIQUE NOT NULL,
            \\`account_id\\` INT,
            \\`ended\\` TINYINT DEFAULT 0,
            \\`action_response\\` TEXT,
            \\`created_at\\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (\\`account_id\\`),
            INDEX (\\`action_id\\`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\");
        echo \"✅ Таблица actions создана\\n\";

        echo \"\\n✅✅✅ БАЗА ДАННЫХ ПОЛНОСТЬЮ ИНИЦИАЛИЗИРОВАНА!\\n\";
    } catch (Exception \$e) {
        echo \"❌ ОШИБКА: \" . \$e->getMessage() . \"\\n\";
        exit(1);
    }
"

if [ $? -eq 0 ]; then
    echo "✅ Инициализация БД успешна!"
else
    echo "⚠️ Ошибка при инициализации БД, но приложение всё равно запустится"
fi
