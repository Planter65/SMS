<?php
require_once 'auth.php';

echo "<h1>Создание тестовых пользователей</h1>";

// Проверяем подключение к базе данных
try {
    $conn = connectToDatabase();
    echo "<p>✅ Подключение к базе данных успешно</p>";
    $conn->close();
} catch (Exception $e) {
    echo "<p>❌ Ошибка подключения к базе данных: " . $e->getMessage() . "</p>";
    exit;
}

// Создаем тестового администратора
$username = 'admin';
$password = 'Admin123!';
$role = 'admin';

echo "<h2>Создание тестового администратора</h2>";
echo "<p>Логин: $username</p>";
echo "<p>Пароль: $password</p>";

$result = registerUser($username, $password, $role);

if ($result['success']) {
    echo "<p style='color: green;'>✅ Администратор создан успешно!</p>";
} else {
    echo "<p style='color: red;'>❌ Ошибка создания администратора: " . $result['message'] . "</p>";
}

// Создаем тестового пользователя
$username = 'user';
$password = 'User123!';
$role = 'user';

echo "<h2>Создание тестового пользователя</h2>";
echo "<p>Логин: $username</p>";
echo "<p>Пароль: $password</p>";

$result = registerUser($username, $password, $role);

if ($result['success']) {
    echo "<p style='color: green;'>✅ Пользователь создан успешно!</p>";
} else {
    echo "<p style='color: red;'>❌ Ошибка создания пользователя: " . $result['message'] . "</p>";
}

echo "<h2>Тестовые данные для входа</h2>";
echo "<ul>";
echo "<li><strong>Администратор:</strong> admin / Admin123!</li>";
echo "<li><strong>Пользователь:</strong> user / User123!</li>";
echo "</ul>";

echo "<p><a href='login.php'>Перейти на страницу входа</a></p>";
echo "<p><a href='test_auth.php'>Запустить тесты системы</a></p>";
?>
