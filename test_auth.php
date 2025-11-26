<?php
require_once 'auth.php';

echo "<h1>Тестирование системы аутентификации</h1>";

// Тест 1: Создание пользователя
echo "<h2>Тест 1: Создание пользователя</h2>";
$result = registerUser('testuser', 'Test123!', 'user');
if ($result['success']) {
    echo "✅ Пользователь создан успешно<br>";
} else {
    echo "❌ Ошибка: " . $result['message'] . "<br>";
}

// Тест 2: Проверка правил пароля
echo "<h2>Тест 2: Проверка правил пароля</h2>";
$testPasswords = [
    '123' => 'Слишком короткий',
    'password' => 'Нет цифр и спецсимволов',
    'Password1' => 'Нет спецсимволов',
    'Password!' => 'Нет цифр',
    'Pass1!' => 'Слишком короткий',
    'Password123!' => 'Правильный пароль'
];

foreach ($testPasswords as $password => $description) {
    $errors = validatePassword($password);
    if (empty($errors)) {
        echo "✅ $description: Пароль валиден<br>";
    } else {
        echo "❌ $description: " . implode(', ', $errors) . "<br>";
    }
}

// Тест 3: Аутентификация
echo "<h2>Тест 3: Аутентификация</h2>";
$result = authenticateUser('testuser', 'Test123!');
if ($result['success']) {
    echo "✅ Аутентификация успешна<br>";
    echo "Роль: " . $result['role'] . "<br>";
} else {
    echo "❌ Ошибка аутентификации: " . $result['message'] . "<br>";
}

// Тест 4: Неправильный пароль
echo "<h2>Тест 4: Неправильный пароль</h2>";
$result = authenticateUser('testuser', 'WrongPassword');
if (!$result['success']) {
    echo "✅ Неправильный пароль отклонен: " . $result['message'] . "<br>";
} else {
    echo "❌ Ошибка: неправильный пароль принят<br>";
}

// Тест 5: Проверка блокировки
echo "<h2>Тест 5: Проверка блокировки</h2>";
for ($i = 0; $i < 6; $i++) {
    $result = authenticateUser('testuser', 'WrongPassword');
    echo "Попытка " . ($i + 1) . ": " . $result['message'] . "<br>";
}

// Тест 6: Проверка блокированного пользователя
echo "<h2>Тест 6: Проверка блокированного пользователя</h2>";
$result = authenticateUser('testuser', 'Test123!');
if (!$result['success']) {
    echo "✅ Заблокированный пользователь не может войти: " . $result['message'] . "<br>";
} else {
    echo "❌ Ошибка: заблокированный пользователь смог войти<br>";
}

echo "<h2>Тестирование завершено</h2>";
echo "<p><a href='login.php'>Перейти на страницу входа</a></p>";
?>
