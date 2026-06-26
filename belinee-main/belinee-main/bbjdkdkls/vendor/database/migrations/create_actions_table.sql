-- Таблица действий для проверки аккаунтов
CREATE TABLE IF NOT EXISTS actions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action_id INT UNSIGNED NOT NULL UNIQUE,
    account_id INT UNSIGNED NOT NULL,
    ended TINYINT(1) NOT NULL DEFAULT 0,
    action_response VARCHAR(50) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_action_id (action_id),
    INDEX idx_account_id (account_id),
    INDEX idx_ended (ended)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
