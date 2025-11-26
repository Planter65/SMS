<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Система СМС информирования - Главное меню</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            padding: 40px;
            text-align: center;
            max-width: 600px;
            width: 100%;
        }

        .header {
            margin-bottom: 40px;
        }

        .header h1 {
            color: #4CAF50;
            font-size: 2.5em;
            margin-bottom: 10px;
        }

        .header p {
            color: #666;
            font-size: 1.1em;
        }

        .menu {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .menu-item {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
            padding: 30px 20px;
            border-radius: 15px;
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
        }

        .menu-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(76, 175, 80, 0.3);
        }

        .menu-item i {
            font-size: 2em;
        }

        .menu-item h3 {
            font-size: 1.2em;
            margin: 0;
        }

        .menu-item p {
            font-size: 0.9em;
            opacity: 0.9;
            margin: 0;
        }

        .menu-item.secondary {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
        }

        .menu-item.secondary:hover {
            box-shadow: 0 15px 30px rgba(108, 117, 125, 0.3);
        }

        .menu-item.danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        }

        .menu-item.danger:hover {
            box-shadow: 0 15px 30px rgba(220, 53, 69, 0.3);
        }

        .info {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
            text-align: left;
        }

        .info h4 {
            color: #333;
            margin-bottom: 10px;
        }

        .info ul {
            color: #666;
            line-height: 1.6;
        }

        .info li {
            margin-bottom: 5px;
        }

        @media (max-width: 768px) {
            .menu {
                grid-template-columns: 1fr;
            }
            
            .header h1 {
                font-size: 2em;
            }
        }
    </style>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📱 Система СМС информирования</h1>
            <p>Главное меню системы</p>
        </div>

        <div class="menu">
            <a href="start.php" class="menu-item">
                <i class="fas fa-play"></i>
                <h3>Запустить систему</h3>
                <p>Войти в систему</p>
            </a>

            <a href="create_users_simple.php" class="menu-item secondary">
                <i class="fas fa-user-plus"></i>
                <h3>Создать пользователей</h3>
                <p>Создать тестовых пользователей</p>
            </a>

            <a href="debug_db.php" class="menu-item secondary">
                <i class="fas fa-database"></i>
                <h3>Диагностика БД</h3>
                <p>Проверить состояние базы данных</p>
            </a>

            <a href="test_auth.php" class="menu-item secondary">
                <i class="fas fa-vial"></i>
                <h3>Тестирование</h3>
                <p>Запустить тесты системы</p>
            </a>
        </div>

        <div class="info">
            <h4>📋 Инструкция по запуску:</h4>
            <ul>
                <li><strong>1.</strong> Сначала создайте пользователей через "Создать пользователей"</li>
                <li><strong>2.</strong> Затем нажмите "Запустить систему" для входа</li>
                <li><strong>3.</strong> Используйте тестовые данные: admin/Admin123! или user/User123!</li>
                <li><strong>4.</strong> При проблемах используйте "Диагностика БД"</li>
            </ul>
        </div>
    </div>
</body>
</html>
