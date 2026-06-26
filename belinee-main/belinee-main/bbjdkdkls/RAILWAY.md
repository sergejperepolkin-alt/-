# Railway Deployment Guide

## 📋 Переменные окружения для Railway

```
DB_HOST=mysql.railway.internal
DB_USER=root
DB_PASSWORD=твой_пароль
DB_NAME=invader_panel
DB_PORT=3306
```

## 🚀 Как развернуть на Railway

### 1️⃣ Подключи MySQL плагин в Railway

- Зайди в свой проект на railway.app
- Нажми "Add" → выбери "MySQL"
- Railway автоматически создаст переменные:
  - `DATABASE_URL` (содержит все параметры)
  - `MYSQLPWD` (пароль)
  - Остальные параметры

### 2️⃣ Распарсь DATABASE_URL

Если Railway использует `DATABASE_URL` формата:
```
mysql://username:password@mysql.railway.internal:3306/database
```

Встав эти переменные в Railway:
```
DB_HOST=mysql.railway.internal
DB_USER=root
DB_PASSWORD=<скопируй из MYSQLPWD>
DB_NAME=<имя БД из DATABASE_URL>
DB_PORT=3306
```

### 3️⃣ Настрой Dockerfile

Cкажи Railway использовать `Dockerfile.railway`:
- Settings → Builder → Docker
- Dockerfile: `Dockerfile.railway`

### 4️⃣ Деплой

Railway автоматически:
✅ Запустит `init-db.sh`  
✅ Создаст все таблицы  
✅ Запустит Apache с PHP  

## 🎉 Результат

После деплоя:

```
http://your-railway-app.railway.app/
```

✅ Регистрация работает  
✅ Вход работает  
✅ Все таблицы в БД  

## 🆘 Если не работает

### Проверь:

1. **Логи Railway:**
   - Deployment Logs → смотри ошибки

2. **Переменные окружения:**
   - Variables → проверь DB_HOST, DB_USER и т.д.

3. **MySQL статус:**
   - Должен быть зелёный статус

4. **Пересоберись:**
   - Redeploy → Manual Deploy

## 💾 Структура БД

Скрипт автоматически создаёт:

```
✅ users              - пользователи
✅ users_sessions     - активные сессии
✅ accounts           - аккаунты бота
✅ actions            - логирование действий
```

---

**Готово! 🎉 Регистрация и вход работают на Railway!**
