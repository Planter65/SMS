<?php
// Конфигурация подключения к базе данных
define('DB_HOST', '134.90.167.42');     // Хост базы данных
define('DB_PORT', 10306);               // Порт базы данных
define('DB_USERNAME', 'Sabanov');       // Имя пользователя
define('DB_PASSWORD', 'Udr-2t');        // Пароль
define('DB_NAME', 'project_Sabanov');   // Имя базы данных

// Функция для подключения к базе данных
function connectToDatabase() {
    $conn = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME, DB_PORT);
    
    // Проверка соединения
    if ($conn->connect_error) {
        die("Ошибка подключения к базе данных: " . $conn->connect_error);
    }
    
    // Установка кодировки
    $conn->set_charset("utf8mb4");
    
    return $conn;
}

// Функция для проверки подключения
function testConnection() {
    try {
        $conn = connectToDatabase();
        echo "✅ Подключение к базе данных успешно установлено!";
        echo "<br>База данных: " . DB_NAME;
        echo "<br>Хост: " . DB_HOST;
        echo "<br>Пользователь: " . DB_USERNAME;
        $conn->close();
        return true;
    } catch (Exception $e) {
        echo "❌ Ошибка подключения: " . $e->getMessage();
        return false;
    }
}
?> 