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

// ============================================
// КОНФИГУРАЦИЯ SMS-ПРОВАЙДЕРОВ
// ============================================

// Тип провайдера: 'emulation', 'smsru', 'smscru'
// 'emulation' - эмуляция (для тестирования, не отправляет реальные SMS)
// 'smsru' - SMS.ru (https://sms.ru)
// 'smscru' - SMSC.ru (https://smsc.ru)
define('SMS_PROVIDER', 'smsru');

// Настройки для SMS.ru
// Получить API ID можно на https://sms.ru/?panel=api
define('SMSRU_API_ID', '91E32E3C-A381-6FCE-B8B6-5752285D0AB5'); // Вставьте ваш API ID от SMS.ru

// Настройки для SMSC.ru
// Зарегистрируйтесь на https://smsc.ru
define('SMSCRU_LOGIN', '');    // Ваш логин от SMSC.ru
define('SMSCRU_PASSWORD', ''); // Ваш пароль от SMSC.ru

// ============================================
// ИНСТРУКЦИЯ ПО НАСТРОЙКЕ:
// ============================================
// 1. Для тестирования (без реальных SMS):
//    - Оставьте SMS_PROVIDER = 'emulation'
//
// 2. Для SMS.ru:
//    - Зарегистрируйтесь на https://sms.ru
//    - Получите API ID в личном кабинете
//    - Установите SMS_PROVIDER = 'smsru'
//    - Укажите SMSRU_API_ID = 'ваш_api_id'
//
// 3. Для SMSC.ru:
//    - Зарегистрируйтесь на https://smsc.ru
//    - Установите SMS_PROVIDER = 'smscru'
//    - Укажите SMSCRU_LOGIN и SMSCRU_PASSWORD
// ============================================
?> 