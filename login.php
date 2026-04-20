<?php
require_once 'auth.php';

// Если пользователь уже авторизован — перенаправляем в зависимости от роли и выбора предприятия
if (isLoggedIn()) {
    $user = getCurrentUser();
    if ($user['role'] === 'admin') {
        header('Location: admin.php');
        exit;
    }
    if (getSelectedCompany() !== null) {
        header('Location: user.php');
        exit;
    }
    header('Location: choose_company.php');
    exit;
}

$error = '';
$success = '';

// Обработка формы входа
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Заполните все поля';
    } else {
        $result = authenticateUser($username, $password);
        if ($result['success']) {
            // Администратор сразу переходит в админку; обычный пользователь — на выбор предприятия
            if ($result['role'] === 'admin') {
                header('Location: admin.php');
            } else {
                header('Location: choose_company.php');
            }
            exit;
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход в систему СМС информирования</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #667eea !important;
            background-image: url('fon.gif') !important;
            background-position: center center !important;
            background-size: cover !important;
            background-attachment: fixed !important;
            background-repeat: no-repeat !important;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 1000px;
            width: 100%;
        }

        .header {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
            padding: 40px;
            text-align: center;
        }

        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }

        .header p {
            font-size: 1.1em;
            opacity: 0.9;
        }

        .main-content {
            display: grid;
            grid-template-columns: 1fr;
            min-height: 500px;
        }

        .info-section {
            background: #f8f9fa;
            padding: 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .info-section h2 {
            color: #333;
            margin-bottom: 20px;
            font-size: 1.8em;
        }

        .info-section p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 15px;
        }

        .features {
            list-style: none;
            margin-top: 20px;
        }

        .features li {
            padding: 8px 0;
            color: #555;
            display: flex;
            align-items: center;
        }

        .features li::before {
            content: "✓";
            color: #4CAF50;
            font-weight: bold;
            margin-right: 10px;
        }

        .auth-section {
            padding: 40px;
        }

        .tabs {
            display: flex;
            margin-bottom: 30px;
            border-bottom: 2px solid #e0e0e0;
        }

        .tab {
            padding: 15px 25px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            color: #666;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
        }

        .tab.active {
            color: #4CAF50;
            border-bottom-color: #4CAF50;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }

        input[type="text"], 
        input[type="password"] {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
        }

        input[type="text"]:focus, 
        input[type="password"]:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        input[type="text"]:disabled, 
        input[type="password"]:disabled {
            background-color: #f5f5f5;
            cursor: not-allowed;
            opacity: 0.6;
        }

        select {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
            cursor: pointer;
        }

        select:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        .btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(76, 175, 80, 0.3);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
        }

        .btn-secondary:hover {
            box-shadow: 0 8px 25px rgba(108, 117, 125, 0.3);
        }

        .status-message {
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            display: none;
        }

        .status-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .status-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .password-rules {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
            font-size: 14px;
            color: #666;
        }

        .password-rules h4 {
            margin-bottom: 10px;
            color: #333;
        }

        .password-rules ul {
            margin: 0;
            padding-left: 20px;
        }

        .password-rules li {
            margin-bottom: 5px;
        }

        @media (max-width: 768px) {
            .main-content {
                grid-template-columns: 1fr;
            }
            
            .header h1 {
                font-size: 2em;
            }
        }
    </style>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📱 Система СМС информирования</h1>
            <p>Автоматизация процесса отправки СМС-оповещений</p>
        </div>

        <div class="main-content">
            <!-- Информационная секция удалена по запросу -->

            <!-- Секция авторизации -->
            <div class="auth-section">
               

                <!-- Форма входа -->
                <div class="tab-content active" id="loginTab">
                    <h3>Вход в систему</h3>
                    <form method="POST" id="loginForm">
                        <input type="hidden" name="action" value="login">
                        
                        <div class="form-group">
                            <label for="username">Имя пользователя:</label>
                            <input type="text" id="username" name="username" required>
                        </div>

                        <div class="form-group">
                            <label for="password">Пароль:</label>
                            <input type="password" id="password" name="password" required>
                        </div>

                        <button type="submit" class="btn">Войти</button>
                    </form>
                </div>

                <!-- Информация о регистрации у администратора -->
                <div class="password-rules" style="margin-top: 10px;">
                    <strong>Регистрация выполняется администратором.</strong> Обратитесь к администратору для добавления учетной записи.
                </div>

                <!-- Сообщения о статусе -->
                <?php if ($error): ?>
                    <div class="status-message status-error" style="display: block;">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="status-message status-success" style="display: block;">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>
