<?php
require_once 'config.php';

echo "<h1>Простое создание пользователей</h1>";

try {
    $conn = connectToDatabase();
    echo "<p>✅ Подключение к базе данных успешно</p>";
    
    // Устанавливаем кодировку для сессии
    $conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    // Создаем администратора
    echo "<h2>Создание администратора</h2>";
    $username = 'admin';
    $password = 'Admin123!';
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $role = 'admin';
    
    // Проверяем, существует ли пользователь
    $stmt = $conn->prepare("SELECT UserID FROM users WHERE Username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo "<p style='color: orange;'>⚠️ Пользователь admin уже существует</p>";
    } else {
        $stmt = $conn->prepare("INSERT INTO users (Username, PasswordHash, Role, Status, PasswordCreatedAt, CreatedAt, UpdatedAt) VALUES (?, ?, ?, 'active', NOW(), NOW(), NOW())");
        $stmt->bind_param("sss", $username, $passwordHash, $role);
        
        if ($stmt->execute()) {
            echo "<p style='color: green;'>✅ Администратор создан успешно!</p>";
        } else {
            echo "<p style='color: red;'>❌ Ошибка создания администратора: " . $stmt->error . "</p>";
        }
    }
    
    // Создаем пользователя
    echo "<h2>Создание пользователя</h2>";
    $username = 'user';
    $password = 'User123!';
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $role = 'user';
    
    // Проверяем, существует ли пользователь
    $stmt = $conn->prepare("SELECT UserID FROM users WHERE Username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo "<p style='color: orange;'>⚠️ Пользователь user уже существует</p>";
    } else {
        $stmt = $conn->prepare("INSERT INTO users (Username, PasswordHash, Role, Status, PasswordCreatedAt, CreatedAt, UpdatedAt) VALUES (?, ?, ?, 'active', NOW(), NOW(), NOW())");
        $stmt->bind_param("sss", $username, $passwordHash, $role);
        
        if ($stmt->execute()) {
            echo "<p style='color: green;'>✅ Пользователь создан успешно!</p>";
        } else {
            echo "<p style='color: red;'>❌ Ошибка создания пользователя: " . $stmt->error . "</p>";
        }
    }
    
    // Показываем всех пользователей
    echo "<h2>Список пользователей</h2>";
    $result = $conn->query("SELECT UserID, Username, Role, Status, CreatedAt FROM users ORDER BY UserID");
    if ($result->num_rows > 0) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr style='background: #f0f0f0;'><th>ID</th><th>Имя пользователя</th><th>Роль</th><th>Статус</th><th>Создан</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['UserID'] . "</td>";
            echo "<td>" . htmlspecialchars($row['Username']) . "</td>";
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
    
    echo "<h2>Тестовые данные для входа</h2>";
    echo "<ul>";
    echo "<li><strong>Администратор:</strong> admin / Admin123!</li>";
    echo "<li><strong>Пользователь:</strong> user / User123!</li>";
    echo "</ul>";
    
    echo "<p><a href='login.php'>Перейти на страницу входа</a></p>";
    echo "<p><a href='debug_db.php'>Диагностика базы данных</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Ошибка: " . $e->getMessage() . "</p>";
    echo "<p><a href='debug_db.php'>Запустить диагностику</a></p>";
}
?>
