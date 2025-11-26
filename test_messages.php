<?php
require_once 'config.php';

echo "<h1>Тест системы сообщений</h1>";

// Функция создания таблицы user_messages
function ensureUserMessagesTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS user_messages (
        UserMessageID INT AUTO_INCREMENT PRIMARY KEY,
        SenderID INT NOT NULL,
        MessageID INT NOT NULL,
        RecipientID INT NOT NULL,
        SentDate DATETIME DEFAULT CURRENT_TIMESTAMP,
        ReadStatus ENUM('unread', 'read') DEFAULT 'unread',
        ReadDate DATETIME NULL,
        FOREIGN KEY (SenderID) REFERENCES users(UserID) ON DELETE CASCADE,
        FOREIGN KEY (MessageID) REFERENCES messages(MessageID) ON DELETE CASCADE,
        FOREIGN KEY (RecipientID) REFERENCES recipients(RecipientID) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

try {
    $conn = connectToDatabase();
    ensureUserMessagesTable($conn);
    
    echo "<h2>✅ Таблица user_messages создана/проверена</h2>";
    
    // Показываем структуру таблицы
    $result = $conn->query("DESCRIBE user_messages");
    echo "<h3>Структура таблицы user_messages:</h3>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Поле</th><th>Тип</th><th>Null</th><th>Ключ</th><th>По умолчанию</th><th>Дополнительно</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['Field']}</td>";
        echo "<td>{$row['Type']}</td>";
        echo "<td>{$row['Null']}</td>";
        echo "<td>{$row['Key']}</td>";
        echo "<td>{$row['Default']}</td>";
        echo "<td>{$row['Extra']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Показываем текущие данные
    echo "<h3>Текущие данные в user_messages:</h3>";
    $result = $conn->query("
        SELECT um.*, u.Username as SenderName, m.Text, r.FullName as RecipientName
        FROM user_messages um
        LEFT JOIN users u ON um.SenderID = u.UserID
        LEFT JOIN messages m ON um.MessageID = m.MessageID
        LEFT JOIN recipients r ON um.RecipientID = r.RecipientID
        ORDER BY um.SentDate DESC
    ");
    
    if ($result && $result->num_rows > 0) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Отправитель</th><th>Сообщение</th><th>Получатель</th><th>Дата отправки</th><th>Статус</th><th>Дата прочтения</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['UserMessageID']}</td>";
            echo "<td>{$row['SenderName']}</td>";
            echo "<td>" . htmlspecialchars($row['Text']) . "</td>";
            echo "<td>{$row['RecipientName']}</td>";
            echo "<td>{$row['SentDate']}</td>";
            echo "<td style='color: " . ($row['ReadStatus'] === 'read' ? 'green' : 'red') . ";'>{$row['ReadStatus']}</td>";
            echo "<td>{$row['ReadDate'] ?: 'Не прочитано'}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>Нет данных в таблице user_messages</p>";
    }
    
    // Показываем статистику
    echo "<h3>Статистика сообщений:</h3>";
    $stats = $conn->query("
        SELECT 
            COUNT(*) as total_messages,
            SUM(CASE WHEN ReadStatus = 'read' THEN 1 ELSE 0 END) as read_messages,
            SUM(CASE WHEN ReadStatus = 'unread' THEN 1 ELSE 0 END) as unread_messages
        FROM user_messages
    ")->fetch_assoc();
    
    echo "<ul>";
    echo "<li><strong>Всего сообщений:</strong> {$stats['total_messages']}</li>";
    echo "<li><strong>Прочитано:</strong> {$stats['read_messages']}</li>";
    echo "<li><strong>Не прочитано:</strong> {$stats['unread_messages']}</li>";
    echo "</ul>";
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Ошибка: " . $e->getMessage() . "</p>";
}
?>
