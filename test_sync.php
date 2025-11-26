<?php
require_once 'config.php';

echo "<h1>Тест синхронизации пользователей</h1>";

// Функция синхронизации пользователей в таблицу recipients
function syncUsersToRecipients($exclude_username = '') {
    try {
        $conn = connectToDatabase();
        $conn->begin_transaction();
        
        // Получаем всех пользователей (исключая указанного пользователя)
        $exclude_condition = $exclude_username ? "AND Username != '$exclude_username'" : '';
        $result = $conn->query("SELECT UserID, Username, Role FROM users WHERE Status = 'active' $exclude_condition");
        $synced_count = 0;
        $errors = [];
        
        echo "<h2>Пользователи в системе:</h2>";
        echo "<ul>";
        while ($user = $result->fetch_assoc()) {
            echo "<li>{$user['Username']} ({$user['Role']}) - ID: {$user['UserID']}</li>";
        }
        echo "</ul>";
        
        // Сбрасываем указатель результата
        $result->data_seek(0);
        
        while ($user = $result->fetch_assoc()) {
            // Проверяем, есть ли уже такой получатель
            $check_stmt = $conn->prepare("SELECT RecipientID FROM recipients WHERE FullName = ?");
            $check_stmt->bind_param("s", $user['Username']);
            $check_stmt->execute();
            $existing = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();
            
            if (!$existing) {
                // Создаем номер телефона на основе UserID (заглушка)
                $phone = '7' . str_pad($user['UserID'], 10, '0', STR_PAD_LEFT);
                
                // Определяем группу на основе роли
                $group_id = ($user['Role'] === 'admin') ? 4 : 1; // 4 = Руководство, 1 = Сотрудники
                
                echo "<p>Добавляем пользователя: {$user['Username']} с номером {$phone} в группу {$group_id}</p>";
                
                // Добавляем пользователя в recipients
                $insert_stmt = $conn->prepare("INSERT INTO recipients (PhoneNumber, FullName, GroupID) VALUES (?, ?, ?)");
                $insert_stmt->bind_param("ssi", $phone, $user['Username'], $group_id);
                
                if ($insert_stmt->execute()) {
                    $synced_count++;
                    echo "<p style='color: green;'>✅ Успешно добавлен: {$user['Username']}</p>";
                } else {
                    $errors[] = "Ошибка добавления пользователя {$user['Username']}: " . $insert_stmt->error;
                    echo "<p style='color: red;'>❌ Ошибка: {$user['Username']} - " . $insert_stmt->error . "</p>";
                }
                $insert_stmt->close();
            } else {
                echo "<p style='color: orange;'>⚠️ Пользователь {$user['Username']} уже существует в recipients</p>";
            }
        }
        
        $conn->commit();
        $conn->close();
        
        echo "<h2>Результат синхронизации:</h2>";
        echo "<p><strong>Добавлено получателей:</strong> {$synced_count}</p>";
        if (!empty($errors)) {
            echo "<p><strong>Ошибки:</strong></p><ul>";
            foreach ($errors as $error) {
                echo "<li style='color: red;'>{$error}</li>";
            }
            echo "</ul>";
        }
        
        return [
            'success' => true,
            'synced_count' => $synced_count,
            'errors' => $errors
        ];
        
    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollback();
            $conn->close();
        }
        echo "<p style='color: red;'>❌ Исключение: " . $e->getMessage() . "</p>";
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Выполняем синхронизацию
$result = syncUsersToRecipients();

// Показываем текущее состояние таблицы recipients
echo "<h2>Текущее состояние таблицы recipients:</h2>";
$conn = connectToDatabase();
$result_query = $conn->query("
    SELECT r.RecipientID, r.FullName, r.PhoneNumber, g.GroupName, 
           CASE WHEN u.UserID IS NOT NULL THEN u.Role ELSE 'recipient' END as UserRole
    FROM recipients r 
    LEFT JOIN groups g ON r.GroupID = g.GroupID 
    LEFT JOIN users u ON r.FullName = u.Username
    ORDER BY r.FullName
");

if ($result_query && $result_query->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>ID</th><th>Имя</th><th>Группа</th><th>Роль</th></tr>";
    while ($row = $result_query->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['RecipientID']}</td>";
        echo "<td>{$row['FullName']}</td>";
        echo "<td>{$row['GroupName']}</td>";
        echo "<td>{$row['UserRole']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>Нет данных в таблице recipients</p>";
}

$conn->close();
?>
