# Invader Panel - Configuration

## Переменные окружения для подключения к БД

Создай файл `.env` в корне проекта и заполни:

```
DB_HOST=localhost
DB_USER=root
DB_PASSWORD=
DB_NAME=invader_panel
DB_PORT=3306
```

## Инициализация БД

### Способ 1: Автоматический (через веб-браузер)

1. Убедись, что MySQL запущена
2. Открой в браузере: `http://localhost/vendor/database/init_database.php`
3. Должно вывести: ✅✅✅ Все таблицы созданы успешно!

### Способ 2: Ручной (через SQL)

1. Откройся в PhpMyAdmin или MySQL CLI
2. Создай БД:
```sql
CREATE DATABASE invader_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE invader_panel;
```

3. Запусти SQL из `vendor/database/init_db.sql`

## Готово! 🎉

Теперь регистрация и вход будут работать!

### Тестирование:

1. Открой: `http://localhost/`
2. Нажми "Зарегистрироваться"
3. Создай учётную запись
4. Вход должен работать ✅
