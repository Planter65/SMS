<?php
require_once 'config.php';

echo "<h1>Диагностика базы данных</h1>";

try {
    $conn = connectToDatabase();
    echo "<p>✅ Подключение к базе данных успешно</p>";
    
    // Проверяем кодировку базы данных
    $result = $conn->query("SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . DB_NAME . "'");
    if ($row = $result->fetch_assoc()) {
        echo "<p><strong>Кодировка базы данных:</strong> " . $row['DEFAULT_CHARACTER_SET_NAME'] . "</p>";
        echo "<p><strong>Коллация базы данных:</strong> " . $row['DEFAULT_COLLATION_NAME'] . "</p>";
    }
    
    // Проверяем кодировку таблицы users
    $result = $conn->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'users'");
    if ($row = $result->fetch_assoc()) {
        echo "<p><strong>Коллация таблицы users:</strong> " . $row['TABLE_COLLATION'] . "</p>";
    }
    
    // Проверяем кодировку колонок таблицы users
    $result = $conn->query("SELECT COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'users' AND CHARACTER_SET_NAME IS NOT NULL");
    echo "<p><strong>Кодировки колонок таблицы users:</strong></p>";
    echo "<ul>";
    while ($row = $result->fetch_assoc()) {
        echo "<li>" . $row['COLUMN_NAME'] . ": " . $row['CHARACTER_SET_NAME'] . " / " . $row['COLLATION_NAME'] . "</li>";
    }
    echo "</ul>";
    
    // Проверяем существование процедур
    $result = $conn->query("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '" . DB_NAME . "'");
    echo "<p><strong>Существующие процедуры:</strong></p>";
    echo "<ul>";
    while ($row = $result->fetch_assoc()) {
        echo "<li>" . $row['ROUTINE_NAME'] . "</li>";
    }
    echo "</ul>";
    
    // Проверяем структуру таблицы users
    $result = $conn->query("DESCRIBE users");
    echo "<p><strong>Структура таблицы users:</strong></p>";
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Поле</th><th>Тип</th><th>Null</th><th>Ключ</th><th>По умолчанию</th><th>Дополнительно</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "<td>" . $row['Default'] . "</td>";
        echo "<td>" . $row['Extra'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Проверяем существующие пользователи
    $result = $conn->query("SELECT UserID, Username, Role, Status, CreatedAt FROM users");
    echo "<p><strong>Существующие пользователи:</strong></p>";
    if ($result->num_rows > 0) {
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>ID</th><th>Имя пользователя</th><th>Роль</th><th>Статус</th><th>Создан</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['UserID'] . "</td>";
            echo "<td>" . $row['Username'] . "</td>";
            echo "<td>" . $row['Role'] . "</td>";
            echo "<td>" . $row['Status'] . "</td>";
            echo "<td>" . $row['CreatedAt'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>Пользователи не найдены</p>";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Ошибка: " . $e->getMessage() . "</p>";
}

echo "<p><a href='create_admin.php'>Попробовать создать пользователей снова</a></p>";
echo "<p><a href='login.php'>Перейти на страницу входа</a></p>";
?>
