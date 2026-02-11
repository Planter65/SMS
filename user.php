<?php
require_once 'auth.php';
require_once 'sms_providers.php';

// Проверяем авторизацию
requireAuth();

// Если пользователь - администратор, перенаправляем на админ панель
if (hasRole('admin')) {
    header('Location: admin.php');
    exit;
}

$user = getCurrentUser();
$message = '';
$error = '';

// Журнал действий (облегченная версия как в admin.php)
function ensureSystemLogsTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS system_logs (
        LogID INT AUTO_INCREMENT PRIMARY KEY,
        Category VARCHAR(50) NOT NULL,
        Action VARCHAR(50) NOT NULL,
        Details TEXT,
        PerformedBy VARCHAR(100),
        IPAddress VARCHAR(45),
        CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Создание таблицы для отслеживания отправленных сообщений пользователями
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

function logSystemAction($category, $action, $details = '') {
    try {
        $conn = connectToDatabase();
        ensureSystemLogsTable($conn);
        $u = function_exists('getCurrentUser') ? getCurrentUser() : null;
        $performedBy = is_array($u) && isset($u['username']) ? $u['username'] : 'user';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt = $conn->prepare("INSERT INTO system_logs (Category, Action, Details, PerformedBy, IPAddress) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $category, $action, $details, $performedBy, $ip);
        $stmt->execute();
        $conn->close();
    } catch (Exception $e) {
        // no-op
    }
}

// Функция синхронизации пользователей в таблицу recipients
function syncUsersToRecipients($exclude_username = '') {
    try {
        $conn = connectToDatabase();
        $conn->begin_transaction();
        
        // Получаем всех пользователей с номерами телефонов (исключая указанного пользователя)
        $exclude_condition = $exclude_username ? "AND Username != '$exclude_username'" : '';
        $result = $conn->query("SELECT UserID, Username, PhoneNumber, Role FROM users WHERE Status = 'active' $exclude_condition");
        $synced_count = 0;
        $updated_count = 0;
        $skipped_count = 0;
        $errors = [];
        
        while ($user = $result->fetch_assoc()) {
            // Пропускаем пользователей без номера телефона
            if (empty($user['PhoneNumber']) || trim($user['PhoneNumber']) === '') {
                $skipped_count++;
                continue;
            }
            
            // Нормализуем номер телефона (убираем пробелы, дефисы и т.д.)
            $phone = preg_replace('/[^0-9+]/', '', trim($user['PhoneNumber']));
            
            // Если номер не начинается с +, добавляем +7 для российских номеров
            if (!empty($phone) && $phone[0] !== '+') {
                // Если номер начинается с 7 или 8, заменяем на +7
                if (preg_match('/^[78]/', $phone)) {
                    $phone = '+7' . substr($phone, 1);
                } else {
                    $phone = '+7' . $phone;
                }
            }
            
            // Проверяем, есть ли уже такой получатель
            $check_stmt = $conn->prepare("SELECT RecipientID, PhoneNumber FROM recipients WHERE FullName = ?");
            $check_stmt->bind_param("s", $user['Username']);
            $check_stmt->execute();
            $existing = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();
            
            // Определяем группу на основе роли
            $group_id = ($user['Role'] === 'admin') ? 4 : 1; // 4 = Руководство, 1 = Сотрудники
            
            if (!$existing) {
                // Добавляем нового получателя
                $insert_stmt = $conn->prepare("INSERT INTO recipients (PhoneNumber, FullName, GroupID) VALUES (?, ?, ?)");
                $insert_stmt->bind_param("ssi", $phone, $user['Username'], $group_id);
                
                if ($insert_stmt->execute()) {
                    $synced_count++;
                } else {
                    $errors[] = "Ошибка добавления пользователя {$user['Username']}: " . $insert_stmt->error;
                }
                $insert_stmt->close();
            } else {
                // Обновляем существующего получателя, если номер телефона изменился
                if ($existing['PhoneNumber'] !== $phone) {
                    $update_stmt = $conn->prepare("UPDATE recipients SET PhoneNumber = ?, GroupID = ? WHERE RecipientID = ?");
                    $update_stmt->bind_param("sii", $phone, $group_id, $existing['RecipientID']);
                    
                    if ($update_stmt->execute()) {
                        $updated_count++;
                    } else {
                        $errors[] = "Ошибка обновления пользователя {$user['Username']}: " . $update_stmt->error;
                    }
                    $update_stmt->close();
                }
            }
        }
        
        $conn->commit();
        $conn->close();
        
        $message = "Синхронизация завершена! Добавлено: $synced_count, обновлено: $updated_count";
        if ($skipped_count > 0) {
            $message .= ", пропущено (без номера телефона): $skipped_count";
        }
        
        return [
            'success' => true,
            'synced_count' => $synced_count,
            'updated_count' => $updated_count,
            'skipped_count' => $skipped_count,
            'errors' => $errors,
            'message' => $message
        ];
        
    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollback();
            $conn->close();
        }
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Обработка синхронизации пользователей
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'sync_users') {
        // Синхронизируем всех пользователей, включая текущего
        $sync_result = syncUsersToRecipients('');
        if ($sync_result['success']) {
            $message = $sync_result['message'] ?? "Синхронизация завершена! Добавлено получателей: " . $sync_result['synced_count'];
            if (!empty($sync_result['errors'])) {
                $message .= ". Ошибки: " . implode(', ', $sync_result['errors']);
            }
            logSystemAction('user', 'sync_users', 'Синхронизировано: ' . $sync_result['synced_count'] . ' пользователей');
        } else {
            $error = 'Ошибка синхронизации: ' . $sync_result['error'];
            logSystemAction('user', 'sync_error', 'Ошибка синхронизации: ' . $sync_result['error']);
        }
    }
}

// Обработка изменения статуса прочтения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_read_status') {
        $user_message_id = $_POST['user_message_id'];
        $new_status = $_POST['read_status'];
        
        try {
            $conn = connectToDatabase();
            
            // Проверяем, что пользователь имеет право изменять статус этого сообщения
            $current_user_id = $user['UserID'] ?? 0;
            $current_username = $user['username'] ?? '';
            
            // Проверяем, является ли пользователь отправителем или получателем сообщения
            $check_stmt = $conn->prepare("
                SELECT um.UserMessageID, um.SenderID, r.FullName as RecipientName
                FROM user_messages um
                JOIN recipients r ON um.RecipientID = r.RecipientID
                WHERE um.UserMessageID = ?
            ");
            $check_stmt->bind_param("i", $user_message_id);
            $check_stmt->execute();
            $message_data = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();
            
            if (!$message_data) {
                $error = 'Сообщение не найдено';
            } else {
                // Проверяем права: пользователь может изменять статус если он отправитель или получатель
                $is_sender = ($message_data['SenderID'] == $current_user_id);
                $is_recipient = ($message_data['RecipientName'] == $current_username);
                
                if (!$is_sender && !$is_recipient) {
                    $error = 'У вас нет прав для изменения статуса этого сообщения';
                } else {
                    $read_date = ($new_status === 'read') ? 'NOW()' : 'NULL';
                    $stmt = $conn->prepare("UPDATE user_messages SET ReadStatus = ?, ReadDate = $read_date WHERE UserMessageID = ?");
                    $stmt->bind_param("si", $new_status, $user_message_id);
                    
                    if ($stmt->execute()) {
                        $message = 'Статус прочтения обновлен!';
                        logSystemAction('user', 'read_status_update', "Статус изменен на: $new_status для сообщения ID: $user_message_id");
                    } else {
                        $error = 'Не удалось обновить статус прочтения';
                    }
                    $stmt->close();
                }
            }
            $conn->close();
        } catch (Exception $e) {
            $error = 'Ошибка обновления статуса: ' . $e->getMessage();
        }
    }
}

// Обработка отправки сообщения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'send_message') {
        $recipient_id = $_POST['recipient_id'];
        $message_text = $_POST['message_text'];
        
        if (empty($recipient_id) || empty($message_text)) {
            $error = 'Заполните все поля';
            logSystemAction('user', 'sms_error', 'Пустые поля получателя/сообщения');
        } else {
            try {
                $conn = connectToDatabase();
                $conn->begin_transaction();
                
                // Создаем таблицы если их нет
                ensureUserMessagesTable($conn);

                // Добавляем сообщение в таблицу messages
                $stmt = $conn->prepare("INSERT INTO messages (Text) VALUES (?)");
                $stmt->bind_param("s", $message_text);
                $stmt->execute();
                $messageId = $conn->insert_id;
                $stmt->close();

                // Получаем номер телефона получателя
                $stmt = $conn->prepare("SELECT PhoneNumber FROM recipients WHERE RecipientID = ?");
                $stmt->bind_param("i", $recipient_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $recipient = $result->fetch_assoc();
                $stmt->close();
                
                if (!$recipient || empty($recipient['PhoneNumber'])) {
                    $conn->rollback();
                    $conn->close();
                    $error = 'У получателя не указан номер телефона';
                    logSystemAction('user', 'sms_error', 'У получателя ID: ' . $recipient_id . ' не указан номер телефона');
                } else {
                    // Добавляем название отправителя в конец сообщения
                    $companyName = '';
                    if (function_exists('getSelectedCompanyName')) {
                        $companyName = getSelectedCompanyName();
                    }
                    $messageWithSender = $message_text;
                    if (!empty($companyName)) {
                        $senderSuffix = ' ' . $companyName;
                        // Проверяем, не превышает ли сообщение лимит в 160 символов
                        if (mb_strlen($message_text . $senderSuffix) <= 160) {
                            $messageWithSender = $message_text . $senderSuffix;
                        }
                    }
                    
                    // Отправка SMS через выбранный провайдер
                    $smsResult = sendSms($recipient['PhoneNumber'], $messageWithSender);
                    $smsStatus = $smsResult['success'] ? 'Доставлено' : ($smsResult['status'] ?? 'Ошибка');

                // Добавляем запись в messagelogs
                    $stmt = $conn->prepare("INSERT INTO messagelogs (MessageID, RecipientID, Status, SentDate) VALUES (?, ?, ?, NOW())");
                    $stmt->bind_param("iis", $messageId, $recipient_id, $smsStatus);
                $stmt->execute();
                $stmt->close();
                
                // Добавляем запись в user_messages для отслеживания
                $sender_id = $user['UserID'] ?? 0;
                $stmt = $conn->prepare("INSERT INTO user_messages (SenderID, MessageID, RecipientID) VALUES (?, ?, ?)");
                $stmt->bind_param("iii", $sender_id, $messageId, $recipient_id);
                $ok = $stmt->execute();
                $stmt->close();

                if ($ok) {
                    $conn->commit();
                    $preview = mb_substr((string)$message_text, 0, 120);
                        $logMessage = 'SMS: ' . $preview . '; получатель ID: ' . $recipient_id;
                        if ($smsResult['success']) {
                            $message = 'SMS сообщение отправлено успешно!';
                            $logMessage .= '; SMS отправлено';
                        } else {
                            $error = 'SMS не отправлено: ' . ($smsResult['message'] ?? 'Ошибка');
                            $logMessage .= '; SMS не отправлено: ' . ($smsResult['message'] ?? 'Ошибка');
                        }
                        logSystemAction('user', 'sms_send', $logMessage);
                } else {
                    $conn->rollback();
                    $error = 'Не удалось отправить SMS сообщение';
                    logSystemAction('user', 'sms_error', 'Ошибка отправки SMS');
                    }
                }
                $conn->close();
            } catch (Exception $e) {
                if (isset($conn)) {
                    $conn->rollback();
                    $conn->close();
                }
                $error = 'Ошибка отправки SMS: ' . $e->getMessage();
                logSystemAction('user', 'sms_error', 'Исключение: ' . $e->getMessage());
            }
        }
    }
}

// Получаем список получателей SMS для выбора
$conn = connectToDatabase();
$recipients = [];
$messages = [];
try {
    // Получаем список получателей SMS (включая синхронизированных пользователей)
    // Исключаем текущего пользователя из списка
    $current_username = $user['username'] ?? '';
    $result = $conn->query("
        SELECT r.RecipientID, r.FullName, r.PhoneNumber, g.GroupName, 
               CASE WHEN u.UserID IS NOT NULL THEN u.Role ELSE 'recipient' END as UserRole
        FROM recipients r 
        LEFT JOIN groups g ON r.GroupID = g.GroupID 
        LEFT JOIN users u ON r.FullName = u.Username
        WHERE r.FullName != '$current_username'
        ORDER BY r.FullName
    ");
    
    while ($row = $result->fetch_assoc()) {
        $recipients[] = $row;
    }
    
    // Получаем отправленные и полученные пользователем сообщения отдельно
    $current_user_id = $user['UserID'] ?? 0;
    $current_username = $user['username'] ?? '';
    
    // Получаем отправленные сообщения
    $sent_messages = [];
    $result = $conn->query("
        SELECT um.UserMessageID, um.SentDate, um.ReadStatus, um.ReadDate,
               m.Text, m.MessageID,
               r.FullName as ContactName, r.PhoneNumber,
               g.GroupName,
               'sent' as MessageType,
               u_sender.Username as SenderName,
               u_recipient.Username as RecipientName
        FROM user_messages um
        JOIN messages m ON um.MessageID = m.MessageID
        JOIN recipients r ON um.RecipientID = r.RecipientID
        LEFT JOIN groups g ON r.GroupID = g.GroupID
        LEFT JOIN users u_sender ON um.SenderID = u_sender.UserID
        LEFT JOIN users u_recipient ON r.FullName = u_recipient.Username
        WHERE um.SenderID = $current_user_id
        ORDER BY um.SentDate DESC 
        LIMIT 15
    ");
    
    while ($row = $result->fetch_assoc()) {
        $sent_messages[] = $row;
    }
    
    // Получаем полученные сообщения
    $received_messages = [];
    $result = $conn->query("
        SELECT um.UserMessageID, um.SentDate, um.ReadStatus, um.ReadDate,
               m.Text, m.MessageID,
               u_sender.Username as ContactName, r.PhoneNumber,
               g.GroupName,
               'received' as MessageType,
               u_sender.Username as SenderName,
               r.FullName as RecipientName
        FROM user_messages um
        JOIN messages m ON um.MessageID = m.MessageID
        JOIN recipients r ON um.RecipientID = r.RecipientID
        LEFT JOIN groups g ON r.GroupID = g.GroupID
        LEFT JOIN users u_sender ON um.SenderID = u_sender.UserID
        WHERE r.FullName = '$current_username'
        ORDER BY um.SentDate DESC 
        LIMIT 15
    ");
    
    while ($row = $result->fetch_assoc()) {
        $received_messages[] = $row;
    }
} catch (Exception $e) {
    $error = 'Ошибка загрузки данных: ' . $e->getMessage();
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="ру">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Панель пользователя - Система СМС информирования</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: transparent;
            min-height: 100vh;
            color: #333;
        }

        .page-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 30px 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-top: 20px;
            border-radius: 15px;
        }

        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            text-align: center;
        }

        .page-header h1 {
            color: #4CAF50;
            font-size: 2.2em;
            margin-bottom: 10px;
        }

        .page-header p {
            color: #666;
            font-size: 1.1em;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .user-name {
            font-weight: 600;
            color: #333;
        }

        .btn {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(76, 175, 80, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        }

        .btn-danger:hover {
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
        }

        .btn-info {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
        }

        .btn-info:hover {
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.3);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .main-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-top: 20px;
        }

        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .card h2 {
            color: #4CAF50;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }

        input[type="text"], 
        textarea, 
        select {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
        }

        input[type="text"]:focus, 
        textarea:focus, 
        select:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        textarea {
            resize: vertical;
            min-height: 120px;
        }

        select {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
            cursor: pointer;
        }

        select:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        small {
            display: block;
            margin-top: 5px;
        }

        .message-item {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin: 15px 0;
            border-left: 4px solid #4CAF50;
            transition: all 0.3s ease;
        }

        .message-item.sent-message {
            border-left-color: #4CAF50;
            background: linear-gradient(135deg, #f8f9fa 0%, #e8f5e8 100%);
        }

        .message-item.received-message {
            border-left-color: #2196F3;
            background: linear-gradient(135deg, #f8f9fa 0%, #e3f2fd 100%);
        }

        .message-item:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        .clickable {
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .clickable:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .message-text {
            font-weight: 600;
            color: #333;
            flex: 1;
            margin-right: 15px;
            font-size: 16px;
        }

        .message-type-badge {
            display: inline-block;
            background: rgba(76, 175, 80, 0.1);
            color: #4CAF50;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .received-message .message-type-badge {
            background: rgba(33, 150, 243, 0.1);
            color: #2196F3;
        }

        .received-status {
            display: flex;
            align-items: center;
        }

        .status-indicator {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            min-width: 120px;
            text-align: center;
        }

        .status-indicator.read {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }

        .status-indicator.unread {
            background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
            color: white;
        }

        .message-status-controls {
            flex-shrink: 0;
        }

        .status-toggle-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 120px;
        }

        .status-toggle-btn.read {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }

        .status-toggle-btn.unread {
            background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
            color: white;
        }

        .status-toggle-btn.unread:hover {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            transform: scale(1.05);
        }

        .status-toggle-btn.read:hover {
            background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
            transform: scale(1.05);
        }

        .status-toggle-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
        }

        .message-details {
            background: rgba(255, 255, 255, 0.7);
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            padding: 5px 0;
        }

        .detail-row:last-child {
            margin-bottom: 0;
        }

        .detail-label {
            font-weight: 600;
            color: #555;
            font-size: 14px;
        }

        .detail-value {
            color: #333;
            font-size: 14px;
        }

        .group-badge {
            background: #e3f2fd;
            color: #1976d2;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            margin-left: 8px;
        }

        .status-read {
            color: #28a745;
            font-weight: 600;
        }

        .status-unread {
            color: #dc3545;
            font-weight: 600;
        }

        .message-status {
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-sent {
            background: #d4edda;
            color: #155724;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-failed {
            background: #f8d7da;
            color: #721c24;
        }

        .status-message {
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            display: none;
        }

        .status-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .status-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .empty-state {
            text-align: center;
            color: #666;
            padding: 40px;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-top: 20px;
        }

        .stat-item {
            text-align: center;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 15px;
            border: 2px solid #e0e0e0;
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            border-color: #4CAF50;
        }

        .stat-number {
            font-size: 2.5em;
            font-weight: 700;
            color: #4CAF50;
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 14px;
            color: #666;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        @media (max-width: 768px) {
            .main-content {
                grid-template-columns: 1fr;
            }
            
            .header-content {
                flex-direction: column;
                gap: 15px;
            }
            
            .message-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .message-text {
                margin-right: 0;
                margin-bottom: 10px;
            }
            
            .message-status-controls {
                align-self: flex-end;
            }
            
            .detail-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }
            
            .status-toggle-btn {
                min-width: 100px;
                font-size: 12px;
                padding: 6px 12px;
            }
            
            .status-indicator {
                min-width: 100px;
                font-size: 12px;
                padding: 6px 12px;
            }
            
            .message-type-badge {
                font-size: 11px;
                padding: 3px 6px;
            }
            
            .stats-container {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .stat-number {
                font-size: 2em;
            }
            
            .stat-item {
                padding: 15px;
            }
        }

        /* Стили модального окна */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            margin: 5% auto;
            padding: 0;
            border-radius: 20px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
            padding: 20px 30px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.5em;
            font-weight: 600;
        }

        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .close:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.1);
        }

        .modal-body {
            padding: 30px;
        }

        .message-preview {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            border-left: 5px solid #4CAF50;
        }

        .message-type-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .message-date {
            color: #666;
            font-size: 14px;
            font-weight: 500;
        }

        .message-text-full {
            font-size: 18px;
            line-height: 1.6;
            color: #333;
            font-weight: 500;
            word-wrap: break-word;
        }

        .message-details-modal {
            background: rgba(255, 255, 255, 0.7);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid #e0e0e0;
        }

        .modal-footer {
            padding: 20px 30px;
            background: #f8f9fa;
            border-radius: 0 0 20px 20px;
            text-align: right;
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.3);
        }

        /* Адаптивность для модального окна */
        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                margin: 10% auto;
            }
            
            .modal-header {
                padding: 15px 20px;
            }
            
            .modal-header h3 {
                font-size: 1.2em;
            }
            
            .modal-body {
                padding: 20px;
            }
            
            .message-preview {
                padding: 20px;
            }
            
            .message-text-full {
                font-size: 16px;
            }
            
            .message-type-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
    </style>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="app-shell">
    <?php include 'navigation.php'; ?>
    <div class="page-header">
        <div class="header-content">
            <h1>📱 Панель пользователя</h1>
            <p>Добро пожаловать, <?php echo htmlspecialchars($user['username']); ?>!</p>
        </div>
    </div>

    <div class="container">
        <!-- Сообщения о статусе -->
        <?php if ($message): ?>
            <div class="status-message status-success" style="display: block;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="status-message status-error" style="display: block;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Синхронизация пользователей -->
        <div class="card" style="margin-bottom: 20px;">
            <h2>🔄 Синхронизация пользователей</h2>
            <p style="color: #666; margin-bottom: 20px;">
                Синхронизируйте пользователей системы с получателями SMS для возможности отправки им сообщений.
            </p>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="sync_users">
                <button type="submit" class="btn btn-info">
                    🔄 Синхронизировать пользователей
                </button>
            </form>
        </div>


        <div class="main-content">
            <!-- Отправка SMS сообщения -->
            <div class="card">
                <h2>📱 Отправка SMS сообщения</h2>
                
                <form method="POST">
                    <input type="hidden" name="action" value="send_message">
                    
                    <div class="form-group">
                        <label for="recipient_id">Выберите получателя SMS:</label>
                        <select id="recipient_id" name="recipient_id" required>
                            <option value="">-- Выберите получателя --</option>
                            <?php foreach ($recipients as $r): ?>
                                <option value="<?php echo $r['RecipientID']; ?>">
                                    <?php echo htmlspecialchars($r['FullName']); ?>
                                    <?php if ($r['GroupName']): ?>
                                        - <?php echo htmlspecialchars($r['GroupName']); ?>
                                    <?php endif; ?>
                                    <?php if ($r['UserRole'] !== 'recipient'): ?>
                                        [<?php echo $r['UserRole'] === 'admin' ? 'Администратор' : 'Пользователь'; ?>]
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="message_text">Текст сообщения:</label>
                        <textarea id="message_text" name="message_text" placeholder="Введите текст сообщения" required maxlength="160"></textarea>
                        <small style="color: #666; font-size: 12px;">Максимум 160 символов</small>
                    </div>

                    <button type="submit" class="btn">Отправить SMS</button>
                </form>
            </div>

            <!-- Полученные сообщения -->
            <div class="card">
                <h2>📥 Полученные сообщения</h2>
                
                <?php if (empty($received_messages)): ?>
                    <div class="empty-state">
                        <p>У вас нет полученных сообщений</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($received_messages as $msg): ?>
                        <div class="message-item received-message clickable" 
                             onclick="openMessageModal(<?php echo htmlspecialchars(json_encode($msg)); ?>)"
                             data-message='<?php echo htmlspecialchars(json_encode($msg)); ?>'>
                            <div class="message-header">
                                <div class="message-text">
                                    <div class="message-type-badge">
                                        📥 Получено
                                    </div>
                                    <?php echo htmlspecialchars($msg['Text']); ?>
                                </div>
                                <div class="message-status-controls" onclick="event.stopPropagation();">
                                    <div class="received-status">
                                        <?php if ($msg['ReadStatus'] === 'unread'): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="update_read_status">
                                                <input type="hidden" name="user_message_id" value="<?php echo $msg['UserMessageID']; ?>">
                                                <input type="hidden" name="read_status" value="read">
                                                <button type="submit" class="status-toggle-btn unread" title="Отметить как прочитанное">
                                                    ✅ Отметить прочитанным
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="update_read_status">
                                                <input type="hidden" name="user_message_id" value="<?php echo $msg['UserMessageID']; ?>">
                                                <input type="hidden" name="read_status" value="unread">
                                                <button type="submit" class="status-toggle-btn read" title="Отметить как непрочитанное">
                                                    ❌ Отметить непрочитанным
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="message-details">
                                <div class="detail-row">
                                    <span class="detail-label">📅 Дата:</span>
                                    <span class="detail-value"><?php echo date('d.m.Y H:i', strtotime($msg['SentDate'])); ?></span>
                                </div>
                                
                                <div class="detail-row">
                                    <span class="detail-label">👤 Отправитель:</span>
                                    <span class="detail-value">
                                        <?php echo htmlspecialchars($msg['SenderName']); ?>
                                        <?php if ($msg['GroupName']): ?>
                                            <span class="group-badge"><?php echo htmlspecialchars($msg['GroupName']); ?></span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                
                                <div class="detail-row">
                                    <span class="detail-label">📊 Статус прочтения:</span>
                                    <span class="detail-value status-<?php echo $msg['ReadStatus']; ?>">
                                        <?php echo $msg['ReadStatus'] === 'read' ? 'Прочитано' : 'Не прочитано'; ?>
                                        <?php if ($msg['ReadDate']): ?>
                                            <small>(<?php echo date('d.m.Y H:i', strtotime($msg['ReadDate'])); ?>)</small>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Вторая строка с отправленными сообщениями -->
        <div class="main-content">
            <!-- Отправленные сообщения -->
            <div class="card">
                <h2>📤 Отправленные сообщения</h2>
                
                <?php if (empty($sent_messages)): ?>
                    <div class="empty-state">
                        <p>Вы еще не отправляли сообщений</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($sent_messages as $msg): ?>
                        <div class="message-item sent-message clickable" 
                             onclick="openMessageModal(<?php echo htmlspecialchars(json_encode($msg)); ?>)"
                             data-message='<?php echo htmlspecialchars(json_encode($msg)); ?>'>
                            <div class="message-header">
                                <div class="message-text">
                                    <div class="message-type-badge">
                                        📤 Отправлено
                                    </div>
                                    <?php echo htmlspecialchars($msg['Text']); ?>
                                </div>
                                <div class="message-status-controls" onclick="event.stopPropagation();">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="update_read_status">
                                        <input type="hidden" name="user_message_id" value="<?php echo $msg['UserMessageID']; ?>">
                                        <input type="hidden" name="read_status" value="<?php echo $msg['ReadStatus'] === 'read' ? 'unread' : 'read'; ?>">
                                        <button type="submit" class="status-toggle-btn <?php echo $msg['ReadStatus'] === 'read' ? 'read' : 'unread'; ?>">
                                            <?php echo $msg['ReadStatus'] === 'read' ? '✅ Прочитано' : '❌ Не прочитано'; ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            
                            <div class="message-details">
                                <div class="detail-row">
                                    <span class="detail-label">📅 Дата:</span>
                                    <span class="detail-value"><?php echo date('d.m.Y H:i', strtotime($msg['SentDate'])); ?></span>
                                </div>
                                
                                <div class="detail-row">
                                    <span class="detail-label">👤 Получатель:</span>
                                    <span class="detail-value">
                                        <?php echo htmlspecialchars($msg['ContactName']); ?>
                                        <?php if ($msg['GroupName']): ?>
                                            <span class="group-badge"><?php echo htmlspecialchars($msg['GroupName']); ?></span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                
                                <div class="detail-row">
                                    <span class="detail-label">📊 Статус прочтения:</span>
                                    <span class="detail-value status-<?php echo $msg['ReadStatus']; ?>">
                                        <?php echo $msg['ReadStatus'] === 'read' ? 'Прочитано' : 'Не прочитано'; ?>
                                        <?php if ($msg['ReadDate']): ?>
                                            <small>(<?php echo date('d.m.Y H:i', strtotime($msg['ReadDate'])); ?>)</small>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Пустая карточка для баланса -->
            <div class="card">
                <h2>📊 Статистика</h2>
                <div class="stats-container">
                    <div class="stat-item">
                        <div class="stat-number"><?php echo count($sent_messages); ?></div>
                        <div class="stat-label">Отправлено</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo count($received_messages); ?></div>
                        <div class="stat-label">Получено</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo count(array_filter($received_messages, function($msg) { return $msg['ReadStatus'] === 'read'; })); ?></div>
                        <div class="stat-label">Прочитано</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно для просмотра сообщения -->
    <div id="messageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Просмотр сообщения</h3>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <div class="message-preview">
                    <div class="message-type-info">
                        <span id="modalMessageType" class="message-type-badge"></span>
                        <span id="modalMessageDate" class="message-date"></span>
                    </div>
                    <div id="modalMessageText" class="message-text-full"></div>
                </div>
                <div class="message-details-modal">
                    <div class="detail-row">
                        <span class="detail-label">👤 Контакт:</span>
                        <span id="modalContact" class="detail-value"></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">📊 Статус:</span>
                        <span id="modalStatus" class="detail-value"></span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">📅 Дата прочтения:</span>
                        <span id="modalReadDate" class="detail-value"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal()">Закрыть</button>
            </div>
        </div>
    </div>

    <script>
        // Счетчик символов для текстового поля
        document.addEventListener('DOMContentLoaded', function() {
            const textarea = document.getElementById('message_text');
            const maxLength = 160;
            
            if (textarea) {
                textarea.addEventListener('input', function() {
                    const remaining = maxLength - this.value.length;
                    const counter = document.getElementById('char-counter');
                    if (counter) {
                        counter.textContent = `Осталось символов: ${remaining}`;
                        counter.style.color = remaining < 20 ? '#dc3545' : '#666';
                    }
                });
                
                // Добавляем счетчик символов
                const counter = document.createElement('small');
                counter.id = 'char-counter';
                counter.style.color = '#666';
                counter.textContent = `Осталось символов: ${maxLength}`;
                textarea.parentNode.appendChild(counter);
            }

            // Обработчики для модального окна
            const modal = document.getElementById('messageModal');
            const closeBtn = document.querySelector('.close');
            
            // Закрытие по клику на крестик
            closeBtn.onclick = function() {
                closeModal();
            }
            
            // Закрытие по клику вне модального окна
            window.onclick = function(event) {
                if (event.target == modal) {
                    closeModal();
                }
            }
            
            // Закрытие по клавише Escape
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeModal();
                }
            });
        });

        // Функция открытия модального окна
        function openMessageModal(messageData) {
            const modal = document.getElementById('messageModal');
            
            // Заполняем данные в модальном окне
            document.getElementById('modalMessageType').textContent = 
                messageData.MessageType === 'sent' ? '📤 Отправлено' : '📥 Получено';
            
            document.getElementById('modalMessageDate').textContent = 
                formatDate(messageData.SentDate);
            
            document.getElementById('modalMessageText').textContent = messageData.Text;
            
            // Определяем контакт в зависимости от типа сообщения
            const contactName = messageData.MessageType === 'sent' ? 
                messageData.ContactName : messageData.SenderName;
            document.getElementById('modalContact').textContent = contactName;
            
            // Статус прочтения
            const statusText = messageData.ReadStatus === 'read' ? 'Прочитано' : 'Не прочитано';
            const statusClass = messageData.ReadStatus === 'read' ? 'status-read' : 'status-unread';
            document.getElementById('modalStatus').textContent = statusText;
            document.getElementById('modalStatus').className = `detail-value ${statusClass}`;
            
            // Дата прочтения
            const readDateText = messageData.ReadDate ? 
                formatDate(messageData.ReadDate) : 'Не прочитано';
            document.getElementById('modalReadDate').textContent = readDateText;
            
            // Автоматически отмечаем полученное сообщение как прочитанное при открытии
            if (messageData.MessageType === 'received' && messageData.ReadStatus === 'unread') {
                markAsRead(messageData.UserMessageID);
            }
            
            // Показываем модальное окно
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden'; // Блокируем прокрутку фона
        }

        // Функция для автоматической отметки сообщения как прочитанного
        function markAsRead(userMessageId) {
            const formData = new FormData();
            formData.append('action', 'update_read_status');
            formData.append('user_message_id', userMessageId);
            formData.append('read_status', 'read');

            fetch('user.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                // Обновляем статус в модальном окне
                document.getElementById('modalStatus').textContent = 'Прочитано';
                document.getElementById('modalStatus').className = 'detail-value status-read';
                document.getElementById('modalReadDate').textContent = formatDate(new Date());
                
                // Обновляем страницу для отображения изменений
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            })
            .catch(error => {
                console.error('Ошибка при отметке сообщения как прочитанного:', error);
            });
        }

        // Функция закрытия модального окна
        function closeModal() {
            const modal = document.getElementById('messageModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto'; // Восстанавливаем прокрутку
        }

        // Функция форматирования даты
        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('ru-RU', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
    </script>
</body>
</html>
