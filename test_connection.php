<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тест подключения к базе данных</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-top: 20px;
        }
        .success {
            color: #28a745;
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .error {
            color: #dc3545;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .info {
            color: #17a2b8;
            background-color: #d1ecf1;
            border: 1px solid #bee5eb;
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }
        th {
            background-color: #4CAF50;
            color: white;
        }
        tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        .btn {
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin: 5px;
        }
        .btn:hover {
            background-color: #45a049;
        }
    </style>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <?php include 'navigation.php'; ?>
    <div class="container">
        <h1>🔧 Тест подключения к базе данных</h1>
        <p>Система СМС информирования - Проверка подключения к phpMyAdmin</p>
        
        <?php
        require_once 'config.php';
        
        echo "<div class='info'>";
        echo "<h3>📋 Параметры подключения:</h3>";
        echo "<strong>Хост:</strong> " . DB_HOST . "<br>";
        echo "<strong>База данных:</strong> " . DB_NAME . "<br>";
        echo "<strong>Пользователь:</strong> " . DB_USERNAME . "<br>";
        echo "<strong>Пароль:</strong> " . (DB_PASSWORD ? '***' : 'пустой') . "<br>";
        if (defined('DB_PORT')) {
            echo "<strong>Порт:</strong> " . DB_PORT . "<br>";
        }
        echo "</div>";
        
        // Тест подключения
        echo "<h3>🔍 Тестирование подключения:</h3>";
        $connectionTest = testConnection();
        
        if ($connectionTest) {
            echo "<div class='success'>";
            echo "<h3>✅ Подключение успешно!</h3>";
            echo "База данных доступна и готова к работе.";
            echo "</div>";
            
            // Проверка структуры базы данных
            echo "<h3>📊 Структура базы данных:</h3>";
            try {
                $conn = connectToDatabase();
                
                // Получение всех таблиц в базе данных
                $result = $conn->query("SHOW TABLES");
                $allTables = [];
                
                if ($result->num_rows > 0) {
                    echo "<div class='success'>✅ Найдено таблиц: " . $result->num_rows . "</div>";
                    
                    while ($row = $result->fetch_array()) {
                        $allTables[] = $row[0];
                    }
                    
                    // Показ структуры каждой таблицы
                    foreach ($allTables as $table) {
                        echo "<div class='success'>✅ Таблица '$table' найдена</div>";
                        
                        // Показ структуры таблицы
                        $structure = $conn->query("DESCRIBE `$table`");
                        if ($structure->num_rows > 0) {
                            echo "<h4>Структура таблицы '$table':</h4>";
                            echo "<table>";
                            echo "<tr><th>Поле</th><th>Тип</th><th>NULL</th><th>Ключ</th><th>По умолчанию</th></tr>";
                            
                            while ($row = $structure->fetch_assoc()) {
                                echo "<tr>";
                                echo "<td>" . $row['Field'] . "</td>";
                                echo "<td>" . $row['Type'] . "</td>";
                                echo "<td>" . $row['Null'] . "</td>";
                                echo "<td>" . $row['Key'] . "</td>";
                                echo "<td>" . $row['Default'] . "</td>";
                                echo "</tr>";
                            }
                            echo "</table>";
                        }
                    }
                } else {
                    echo "<div class='error'>❌ В базе данных нет таблиц</div>";
                }
                
                $conn->close();
                
            } catch (Exception $e) {
                echo "<div class='error'>❌ Ошибка при проверке структуры: " . $e->getMessage() . "</div>";
            }
        } else {
            echo "<div class='error'>";
            echo "<h3>❌ Ошибка подключения!</h3>";
            echo "Проверьте следующие параметры:";
            echo "<ul>";
            echo "<li>XAMPP запущен и работает</li>";
            echo "<li>MySQL сервис активен</li>";
            echo "<li>База данных 'sms_informing' создана</li>";
            echo "<li>Правильные учетные данные пользователя</li>";
            echo "</ul>";
            echo "</div>";
        }
        ?>
        
        <h3>🔧 Возможные решения проблем:</h3>
        <div class='info'>
            <h4>Если подключение не работает:</h4>
            <ol>
                <li><strong>Проверьте XAMPP:</strong> Убедитесь, что Apache и MySQL запущены в панели управления XAMPP</li>
                <li><strong>Проверьте базу данных:</strong> Откройте phpMyAdmin (http://localhost/phpmyadmin) и убедитесь, что база 'sms_informing' существует</li>
                <li><strong>Проверьте пользователя:</strong> По умолчанию в XAMPP используется пользователь 'root' без пароля</li>
                <li><strong>Импортируйте данные:</strong> Если база пустая, импортируйте файл sms_informing.sql через phpMyAdmin</li>
            </ol>
        </div>
        
        <div style="margin-top: 30px; text-align: center;">
            <a href="http://localhost/phpmyadmin" class="btn" target="_blank">🗄️ phpMyAdmin</a>
        </div>
    </div>
</body>
</html> 