<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Авторизация</title>
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
            background: #0f0f23;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .login-container {
            width: 100%;
            max-width: 420px;
            animation: slideUp 0.5s ease-out;
            position: relative;
            z-index: 1;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .login-header h1 {
            color: #ffffff;
            font-size: 48px;
            font-family: 'Montserrat', sans-serif;
            font-weight: 700;
            margin-bottom: 8px;
            text-shadow: 0 2px 10px rgba(79, 70, 229, 0.5);
        }

        .login-header p {
            color: #c7d2fe;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            color: #e0e7ff;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .form-group input {
            width: 100%;
            padding: 12px 14px;
            box-sizing: border-box;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.05);
            color: #ffffff;
            font-size: 15px;
            font-weight: 550;
            transition: border-color 0.2s;
            outline: none;
        }

        .form-group input:focus {
            border-color: #4F46E5;
            outline: none;
        }

        .form-group input::placeholder {
            color: rgba(255, 255, 255, 0.5);
        }

        .auth-form {
            display: none;
        }

        .auth-form.active {
            display: block;
        }

        .login-button {
            width: 100%;
            padding: 14px;
            background: rgba(79, 70, 229, 0.8);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 8px;
        }

        .login-button:hover {
            background: rgba(99, 102, 241, 0.9);
            transform: translateY(-2px);
        }

        .login-button:active {
            transform: translateY(0);
        }

        .register-button {
            width: 100%;
            padding: 14px;
            background: transparent;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            color: #a5b4fc;
            border: 1px solid rgba(79, 70, 229, 0.5);
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 0;
        }

        .register-button:hover {
            background: rgba(79, 70, 229, 0.1);
            border-color: rgba(79, 70, 229, 0.8);
            color: #c7d2fe;
            transform: translateY(-2px);
        }

        .register-button:active {
            transform: translateY(0);
        }

        .forgot-password {
            text-align: center;
            margin-top: 12px;
        }

        .forgot-password a {
            color: #a5b4fc;
            text-decoration: none;
            font-size: 14px;
            transition: color 0.3s ease;
        }

        .forgot-password a:hover {
            color: #c7d2fe;
            text-decoration: underline;
        }

        .error-message {
            background: rgba(220, 38, 38, 0.2);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            color: #fca5a5;
            padding: 12px;
            border-radius: 12px;
            font-size: 14px;
            margin-bottom: 20px;
            display: none;
            border: 1px solid rgba(220, 38, 38, 0.3);
        }

        .error-message.show {
            display: block;
        }

        @media (max-width: 480px) {
            .login-header h1 {
                font-size: 40px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>INVADER PANEL</h1>
        </div>

        <div class="error-message" id="errorMessage"></div>

        <form id="loginForm" class="auth-form active" onsubmit="handleLogin(event)">
            <div class="form-group">
                <label for="username">Логин</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    placeholder="Введите логин"
                    required
                    autocomplete="username"
                >
            </div>

            <div class="form-group">
                <label for="password">Пароль</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Введите пароль"
                    required
                    autocomplete="current-password"
                >
            </div>

            <button type="submit" class="login-button">Войти</button>
        </form>

        <form id="registerForm" class="auth-form" onsubmit="handleRegister(event)">
            <div class="form-group">
                <label for="regUsername">Логин</label>
                <input
                    type="text"
                    id="regUsername"
                    name="regUsername"
                    placeholder="Придумайте логин"
                    required
                    autocomplete="username"
                >
            </div>

            <div class="form-group">
                <label for="regPassword">Пароль</label>
                <input
                    type="password"
                    id="regPassword"
                    name="regPassword"
                    placeholder="Придумайте пароль"
                    required
                    autocomplete="new-password"
                >
            </div>

            <div class="form-group">
                <label for="regPassword2">Повторите пароль</label>
                <input
                    type="password"
                    id="regPassword2"
                    name="regPassword2"
                    placeholder="Повторите пароль"
                    required
                    autocomplete="new-password"
                >
            </div>

            <button type="submit" class="login-button">Зарегистрироваться</button>
        </form>

        <div class="forgot-password">
            <button type="button" class="register-button" id="toggleAuthBtn" onclick="toggleAuthForm()">Зарегистрироваться</button>
        </div>
    </div>

    <script>
        const loginForm = document.getElementById('loginForm');
        const registerForm = document.getElementById('registerForm');
        const toggleAuthBtn = document.getElementById('toggleAuthBtn');

        function toggleAuthForm() {
            if (loginForm.classList.contains('active')) {
                loginForm.classList.remove('active');
                registerForm.classList.add('active');
                toggleAuthBtn.textContent = 'Войти';
            } else {
                registerForm.classList.remove('active');
                loginForm.classList.add('active');
                toggleAuthBtn.textContent = 'Зарегистрироваться';
            }

            const errorMessage = document.getElementById('errorMessage');
            if (errorMessage) {
                errorMessage.classList.remove('show');
                errorMessage.textContent = '';
            }
        }

        function handleLogin(event) {
            event.preventDefault();

            const username = document.getElementById('username').value;
            const password = document.getElementById('password').value;
            const errorMessage = document.getElementById('errorMessage');

            errorMessage.classList.remove('show');
            errorMessage.textContent = '';

            if (!username || !password) {
                showError('Пожалуйста, заполните все поля');
                return;
            }

            (async () => {
                try {
                    const response = await fetch('/vendor/auth/auth.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            login: username,
                            password: password
                        })
                    });

                    const data = await response.json();

                    if (data.success) {
                        window.location.href = '/dashboard.php';
                    } else {
                        if (data.error === 'auth_failed') {
                            showError('Неверный логин или пароль');
                        } else if (data.error === 'invalid_request') {
                            showError('Заполните все поля');
                        } else {
                            showError('Ошибка авторизации. Попробуйте ещё раз.');
                        }
                    }
                } catch (error) {
                    console.error('Auth error:', error);
                    showError('Ошибка сети. Попробуйте ещё раз.');
                }
            })();
        }

        function handleRegister(event) {
            event.preventDefault();

            const username = document.getElementById('regUsername').value;
            const password = document.getElementById('regPassword').value;
            const password2 = document.getElementById('regPassword2').value;
            const errorMessage = document.getElementById('errorMessage');

            errorMessage.classList.remove('show');
            errorMessage.textContent = '';

            if (!username || !password || !password2) {
                showError('Пожалуйста, заполните все поля');
                return;
            }

            if (password !== password2) {
                showError('Пароли не совпадают');
                return;
            }

            if (password.length < 6) {
                showError('Пароль должен быть не менее 6 символов');
                return;
            }

            (async () => {
                try {
                    const response = await fetch('/vendor/auth/register.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            login: username,
                            email: '',
                            password: password,
                            password2: password2
                        })
                    });

                    const data = await response.json();

                    if (data.success) {
                        window.location.href = '/dashboard.php';
                    } else {
                        if (data.error === 'login_taken') {
                            showError('Этот логин уже занят');
                        } else if (data.error === 'email_taken') {
                            showError('Этот email уже занят');
                        } else if (data.error === 'password_mismatch') {
                            showError('Пароли не совпадают');
                        } else if (data.error === 'login_invalid') {
                            showError('Логин должен быть от 3 до 64 символов');
                        } else if (data.error === 'password_too_short') {
                            showError('Пароль должен быть не менее 6 символов');
                        } else if (data.error === 'invalid_request') {
                            showError('Заполните все поля');
                        } else {
                            showError('Ошибка регистрации. Попробуйте ещё раз.');
                        }
                    }
                } catch (error) {
                    console.error('Register error:', error);
                    showError('Ошибка сети. Попробуйте ещё раз.');
                }
            })();
        }

        function showError(message) {
            const errorMessage = document.getElementById('errorMessage');
            errorMessage.textContent = message;
            errorMessage.classList.add('show');
        }
    </script>
</body>
</html>
