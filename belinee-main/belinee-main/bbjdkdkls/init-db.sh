#!/bin/bash
sleep 5
if [ -f "init_database.php" ]; then
    echo "Запуск инициализации базы данных..."
    php init_database.php
else
    echo "Файл init_database.php не найден, пропускаем..."
fi
```»
