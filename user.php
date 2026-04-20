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

// У пользователя должно быть выбрано предприятие
if (getSelectedCompany() === null) {
    header('Location: choose_company.php');
    exit;
}

$user = getCurrentUser();
$message = '';
$error = '';

// Журнал действий (облегченная версия как в admin.php)
function ensureSystemLogsTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS system_logs (
        LogID INT AUTO_INCREMENT PRIMARY KEY,
        Category VARCHAR(50) NOT NULL,
        Action VARCHAR(50) NOT NULL,
        Details TEXT,
        PerformedBy VARCHAR(100),
        IPAddress VARCHAR(45),
        CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    if (!$conn->query($sql)) {
        error_log('user ensureSystemLogsTable failed: ' . $conn->error);
    }
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

// Расширение таблицы логов отправки для статусов провайдера (Beeline A2P и др.)
function ensureMessageLogsExtended($conn) {
    $cols = [];
    $res = @$conn->query("SHOW COLUMNS FROM messagelogs");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cols[(string)$row['Field']] = true;
        }
    }
    $adds = [];
    if (!isset($cols['Provider'])) $adds[] = "ADD COLUMN Provider VARCHAR(32) NULL AFTER Status";
    if (!isset($cols['ProviderSmsId'])) $adds[] = "ADD COLUMN ProviderSmsId VARCHAR(64) NULL AFTER Provider";
    if (!isset($cols['ProviderSmsGroupId'])) $adds[] = "ADD COLUMN ProviderSmsGroupId VARCHAR(64) NULL AFTER ProviderSmsId";
    if (!isset($cols['ProviderStatusText'])) $adds[] = "ADD COLUMN ProviderStatusText TEXT NULL AFTER ProviderSmsGroupId";
    if (!isset($cols['UpdatedAt'])) $adds[] = "ADD COLUMN UpdatedAt DATETIME NULL AFTER SentDate";
    if (!empty($adds)) {
        foreach ($adds as $sqlAdd) {
            @$conn->query("ALTER TABLE messagelogs {$sqlAdd}");
        }
    }
}

function logSystemAction($category, $action, $details = '') {
    try {
        $conn = connectToDatabase();
        ensureSystemLogsTable($conn);
        $conn->set_charset('utf8mb4');
        $u = function_exists('getCurrentUser') ? getCurrentUser() : null;
        $performedBy = is_array($u) && isset($u['username']) ? $u['username'] : 'user';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt = $conn->prepare("INSERT INTO system_logs (Category, Action, Details, PerformedBy, IPAddress) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            error_log('user logSystemAction prepare failed: ' . $conn->error);
            $conn->close();
            return;
        }
        $stmt->bind_param("sssss", $category, $action, $details, $performedBy, $ip);
        if (!$stmt->execute()) {
            error_log('user logSystemAction execute failed: ' . $stmt->error);
        }
        $stmt->close();
        $conn->close();
    } catch (Exception $e) {
        error_log('user logSystemAction exception: ' . $e->getMessage());
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
    if ($_POST['action'] === 'get_messages_filter') {
        $current_user_id = (int)($user['id'] ?? 0);
        $current_username = $user['username'] ?? '';
        $msg_filter = isset($_POST['msg_filter']) ? $_POST['msg_filter'] : 'all';
        $date_cond = '';
        if ($msg_filter === 'day') $date_cond = ' AND um.SentDate >= DATE_SUB(NOW(), INTERVAL 1 DAY)';
        elseif ($msg_filter === 'week') $date_cond = ' AND um.SentDate >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
        elseif ($msg_filter === 'month') $date_cond = ' AND um.SentDate >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
        $conn = connectToDatabase();
        $sent_messages = [];
        $result = $conn->query("
            SELECT um.UserMessageID, um.SentDate, um.ReadStatus, um.ReadDate,
                   m.Text, m.MessageID,
                   r.RecipientID, r.FullName as ContactName, r.PhoneNumber,
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
            WHERE um.SenderID = $current_user_id $date_cond
            ORDER BY um.SentDate DESC 
            LIMIT 100
        ");
        while ($row = $result->fetch_assoc()) { $sent_messages[] = $row; }
        $received_messages = [];
        $result = $conn->query("
            SELECT um.UserMessageID, um.SentDate, um.ReadStatus, um.ReadDate,
                   m.Text, m.MessageID,
                   u_sender.Username as ContactName, r.PhoneNumber,
                   g.GroupName,
                   'received' as MessageType,
                   u_sender.Username as SenderName,
                   r.FullName as RecipientName,
                   rs.RecipientID as SenderRecipientID
            FROM user_messages um
            JOIN messages m ON um.MessageID = m.MessageID
            JOIN recipients r ON um.RecipientID = r.RecipientID
            LEFT JOIN groups g ON r.GroupID = g.GroupID
            LEFT JOIN users u_sender ON um.SenderID = u_sender.UserID
            LEFT JOIN recipients rs ON rs.FullName = u_sender.Username
            WHERE r.FullName = '" . $conn->real_escape_string($current_username) . "' $date_cond
            ORDER BY um.SentDate DESC 
            LIMIT 100
        ");
        while ($row = $result->fetch_assoc()) { $received_messages[] = $row; }
        $all_msgs = [];
        foreach ($sent_messages as $m) {
            $all_msgs[] = ['contact' => $m['ContactName'], 'recipient_id' => (int)$m['RecipientID'], 'text' => $m['Text'], 'date' => $m['SentDate'], 'type' => 'sent', 'UserMessageID' => $m['UserMessageID'], 'ReadStatus' => $m['ReadStatus']];
        }
        foreach ($received_messages as $m) {
            $all_msgs[] = ['contact' => $m['ContactName'], 'recipient_id' => isset($m['SenderRecipientID']) ? (int)$m['SenderRecipientID'] : 0, 'text' => $m['Text'], 'date' => $m['SentDate'], 'type' => 'received', 'UserMessageID' => $m['UserMessageID'], 'ReadStatus' => $m['ReadStatus']];
        }
        usort($all_msgs, function($a, $b) { return strtotime($b['date']) - strtotime($a['date']); });
        $conversations = [];
        foreach ($all_msgs as $m) {
            $key = $m['contact'];
            if (!isset($conversations[$key])) {
                $conversations[$key] = ['contact' => $m['contact'], 'recipient_id' => $m['recipient_id'], 'last_text' => $m['text'], 'last_date' => $m['date'], 'messages' => []];
            }
            $conversations[$key]['messages'][] = $m;
        }
        foreach ($conversations as $k => $conv) {
            usort($conversations[$k]['messages'], function($a, $b) { return strtotime($a['date']) - strtotime($b['date']); });
        }
        $conversations = array_values($conversations);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'sent_messages' => $sent_messages, 'received_messages' => $received_messages, 'conversations' => $conversations]);
        $conn->close();
        exit;
    }

    if ($_POST['action'] === 'send_feedback') {
        header('Content-Type: application/json; charset=utf-8');
        $text = trim($_POST['message'] ?? '');
        if ($text === '') {
            echo json_encode(['success' => false, 'message' => 'Введите текст сообщения']); exit;
        }
        if (mb_strlen($text) > 1000) {
            echo json_encode(['success' => false, 'message' => 'Сообщение не должно превышать 1000 символов']); exit;
        }
        try {
            $conn = connectToDatabase();
            $username = $user['username'] ?? 'unknown';
            $currentUserId = (int)($user['id'] ?? 0);
            $selectedAdminUserId = (int)($_POST['admin_user_id'] ?? 0);

            // 1) Сохраняем в таблицу user_feedback (как журнал обращений)
            $conn->query("CREATE TABLE IF NOT EXISTS user_feedback (
                FeedbackID INT AUTO_INCREMENT PRIMARY KEY,
                Username VARCHAR(255) NOT NULL,
                Status VARCHAR(50) NOT NULL,
                Message TEXT NOT NULL,
                CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $stmt = $conn->prepare("INSERT INTO user_feedback (Username, Status, Message, CreatedAt) VALUES (?, 'Новое', ?, NOW())");
            $stmt->bind_param("ss", $username, $text);
            $stmt->execute();
            $stmt->close();

            // 2) Создаём внутренние личные сообщения выбранному администратору (или всем, если не выбрали)
            // Таблица user_messages уже создаётся в user.php при инициализации, но на всякий случай убедимся, что она есть
            $conn->query("CREATE TABLE IF NOT EXISTS user_messages (
                UserMessageID INT AUTO_INCREMENT PRIMARY KEY,
                SenderID INT NOT NULL,
                MessageID INT NOT NULL,
                RecipientID INT NOT NULL,
                SentDate DATETIME DEFAULT CURRENT_TIMESTAMP,
                ReadStatus ENUM('unread', 'read') DEFAULT 'unread',
                ReadDate DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Берём администраторов и их получателей (RecipientID)
            $admins = [];
            $stmtAdmins = $conn->prepare("
                SELECT u.UserID, u.Username, r.RecipientID
                FROM users u
                LEFT JOIN recipients r ON r.FullName = u.Username
                WHERE u.Role = 'admin'
                  AND u.Status = 'active'
                  AND (? = 0 OR u.UserID = ?)
            ");
            if ($stmtAdmins) {
                $stmtAdmins->bind_param("ii", $selectedAdminUserId, $selectedAdminUserId);
                $stmtAdmins->execute();
                $res = $stmtAdmins->get_result();
                while ($row = $res->fetch_assoc()) {
                    if (!empty($row['UserID']) && !empty($row['RecipientID'])) {
                        $admins[] = [
                            'user_id' => (int)$row['UserID'],
                            'username' => $row['Username'],
                            'recipient_id' => (int)$row['RecipientID'],
                        ];
                    }
                }
                $stmtAdmins->close();
            }

            if (empty($admins)) {
                if ($selectedAdminUserId > 0) {
                    throw new Exception('Выбранный администратор не найден или у него нет получателя для сообщений.');
                }
                throw new Exception('Администраторы не найдены (нет получателя для сообщений).');
            }

            if (!empty($admins) && $currentUserId > 0) {
                // Создаём запись в messages
                $stmt = $conn->prepare("INSERT INTO messages (Text) VALUES (?)");
                $stmt->bind_param("s", $text);
                $stmt->execute();
                $messageId = (int)$conn->insert_id;
                $stmt->close();

                // Для каждого администратора создаём запись в user_messages (только личное сообщение, без SMS)
                $stmtUm = $conn->prepare("INSERT INTO user_messages (SenderID, MessageID, RecipientID) VALUES (?, ?, ?)");
                foreach ($admins as $adm) {
                    $recipientId = $adm['recipient_id'];
                    $stmtUm->bind_param("iii", $currentUserId, $messageId, $recipientId);
                    $stmtUm->execute();
                }
                $stmtUm->close();
            }

            $conn->close();
            $messageAdmins = array_values(array_map(function ($a) { return $a['username'] ?? ''; }, $admins));
            $messageAdmins = array_values(array_filter($messageAdmins));
            if ($selectedAdminUserId > 0 && count($messageAdmins) === 1) {
                echo json_encode(['success' => true, 'message' => 'Сообщение отправлено администратору: ' . $messageAdmins[0]]);
            } else {
                echo json_encode(['success' => true, 'message' => 'Сообщение отправлено администратору(ам)']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
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
            $current_user_id = (int)($user['id'] ?? 0);
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

// AJAX: обновление статуса доставки SMS у провайдера (Beeline A2P) по LogID
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'refresh_sms_status') {
        header('Content-Type: application/json; charset=utf-8');
        $logId = (int)($_POST['log_id'] ?? 0);
        if ($logId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Не задан log_id'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            $settings = loadSmsSettings();
            $provider = (string)($settings['SMS_PROVIDER'] ?? 'emulation');
            if ($provider !== 'beeline_a2p') {
                echo json_encode(['success' => false, 'message' => 'Провайдер не Beeline A2P'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Конфиг Beeline A2P берём из local_beeline_sms_config.php (как в send_test_sms.php)
            $cfg = [];
            $configPath = __DIR__ . DIRECTORY_SEPARATOR . 'local_beeline_sms_config.php';
            if (file_exists($configPath)) {
                /** @noinspection PhpIncludeInspection */
                require $configPath;
            }
            $login = trim((string)($cfg['login'] ?? ''));
            $password = trim((string)($cfg['password'] ?? ''));
            $host = trim((string)($cfg['host'] ?? ''));
            if ($login === '' || $password === '' || $host === '') {
                echo json_encode(['success' => false, 'message' => 'Не заполнены login/password/host в local_beeline_sms_config.php'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $conn = connectToDatabase();
            ensureMessageLogsExtended($conn);
            $stmt = $conn->prepare("SELECT LogID, ProviderSmsId, ProviderSmsGroupId FROM messagelogs WHERE LogID = ?");
            $stmt->bind_param("i", $logId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) {
                $conn->close();
                echo json_encode(['success' => false, 'message' => 'Лог не найден'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $smsId = trim((string)($row['ProviderSmsId'] ?? ''));
            $smsGroupId = trim((string)($row['ProviderSmsGroupId'] ?? ''));
            if ($smsId === '' && $smsGroupId === '') {
                $conn->close();
                echo json_encode(['success' => false, 'message' => 'В логе нет sms_id/sms_group_id для проверки'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            require_once __DIR__ . '/API/HTTPS/test/QTSMS.class.php';
            $qtsms = new QTSMS($login, $password, $host);
            $xml = $smsId !== '' ? (string)$qtsms->status_sms_id($smsId) : (string)$qtsms->status_sms_group_id($smsGroupId);

            // Парсим ответ статуса (best-effort)
            $code = '';
            $statusText = '';
            $sentFlag = null;
            $closedFlag = null;
            $newStatus = 'В очереди';

            $sx = @simplexml_load_string($xml);
            if ($sx !== false) {
                $msgNodes = $sx->xpath('//MESSAGE');
                if ($msgNodes && isset($msgNodes[0])) {
                    $m = $msgNodes[0];
                    $code = trim((string)($m->SMSSTC_CODE ?? ''));
                    $statusText = trim((string)($m->SMS_STATUS ?? ''));
                    $sentFlag = (string)($m->SMS_SENT ?? '');
                    $closedFlag = (string)($m->SMS_CLOSED ?? '');
                }
            }

            if ($code === 'accepted' || mb_stripos($statusText, 'принят') !== false) {
                $newStatus = 'Принято';
            }
            if ($sentFlag === '1') {
                $newStatus = 'Отправлено';
            }
            if ($closedFlag === '1' && $sentFlag === '1') {
                $newStatus = 'Доставлено';
            }
            if ($code !== '' && $code !== 'accepted' && mb_stripos($statusText, 'не разреш') !== false) {
                $newStatus = 'Ошибка';
            }
            if ($code !== '' && $code !== 'accepted' && mb_stripos($statusText, 'ошиб') !== false) {
                $newStatus = 'Ошибка';
            }

            $provText = trim($code . ($statusText !== '' ? (': ' . $statusText) : ''));
            $stmt = $conn->prepare("UPDATE messagelogs SET Status = ?, Provider = 'beeline_a2p', ProviderStatusText = ?, UpdatedAt = NOW() WHERE LogID = ?");
            $stmt->bind_param("ssi", $newStatus, $provText, $logId);
            $stmt->execute();
            $stmt->close();
            $conn->close();

            echo json_encode([
                'success' => true,
                'status' => $newStatus,
                'provider_status_text' => $provText,
                'xml' => $xml,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Ошибка проверки статуса: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

// Обработка отправки сообщения (рассылка нескольким получателям)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'send_message') {
        $recipient_ids = isset($_POST['recipients']) && is_array($_POST['recipients']) ? $_POST['recipients'] : (isset($_POST['recipient_id']) ? [$_POST['recipient_id']] : []);
        $recipient_ids = array_filter(array_map('intval', $recipient_ids));
        $message_text = trim($_POST['message_text'] ?? '');
        
        if (empty($recipient_ids) || $message_text === '') {
            $error = 'Выберите хотя бы одного получателя и введите текст';
            logSystemAction('user', 'sms_error', 'Пустые поля получателя/сообщения');
        } else {
            try {
                $conn = connectToDatabase();
                ensureMessageLogsExtended($conn);
                $companyName = function_exists('getSelectedCompanyName') ? getSelectedCompanyName() : '';
                $messageWithSender = $message_text;
                if (!empty($companyName) && mb_strlen($message_text . ' ' . $companyName) <= 600) {
                    $messageWithSender = $message_text . ' ' . $companyName;
                }
                $sender_id = (int)($user['id'] ?? 0);
                $sent_ok = 0;
                $sent_fail = 0;
                $smsResult = ['success' => false, 'message' => 'Ошибка'];
                ensureUserMessagesTable($conn);
                foreach ($recipient_ids as $recipient_id) {
                    $conn->begin_transaction();
                    $stmt = $conn->prepare("
                        SELECT r.PhoneNumber, COALESCE(u.Role, '') AS UserRole
                        FROM recipients r
                        LEFT JOIN users u ON r.FullName = u.Username
                        WHERE r.RecipientID = ?
                    ");
                    $stmt->bind_param("i", $recipient_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $recipient = $result->fetch_assoc();
                    $stmt->close();
                    if (!$recipient || (($recipient['UserRole'] ?? '') === 'admin') || empty($recipient['PhoneNumber'])) {
                        $conn->rollback();
                        $sent_fail++;
                        continue;
                    }
                    $stmt = $conn->prepare("INSERT INTO messages (Text) VALUES (?)");
                    $stmt->bind_param("s", $message_text);
                    $stmt->execute();
                    $messageId = $conn->insert_id;
                    $stmt->close();
                    $smsResult = sendSms($recipient['PhoneNumber'], $messageWithSender);
                    // Для Beeline A2P статус "accepted" означает "принято", а не "доставлено"
                    $smsStatus = (string)($smsResult['status'] ?? ($smsResult['success'] ? 'Отправлено' : 'Ошибка'));
                    $provider = (string)(loadSmsSettings()['SMS_PROVIDER'] ?? '');
                    $psid = isset($smsResult['sms_id']) ? (string)$smsResult['sms_id'] : null;
                    $pgid = isset($smsResult['sms_group_id']) ? (string)$smsResult['sms_group_id'] : null;
                    $ptext = isset($smsResult['message']) ? (string)$smsResult['message'] : null;
                    $stmt = $conn->prepare("INSERT INTO messagelogs (MessageID, RecipientID, Status, Provider, ProviderSmsId, ProviderSmsGroupId, ProviderStatusText, SentDate) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->bind_param("iisssss", $messageId, $recipient_id, $smsStatus, $provider, $psid, $pgid, $ptext);
                    $stmt->execute();
                    $logIdInserted = (int)$conn->insert_id;
                    $stmt->close();
                    $stmt = $conn->prepare("INSERT INTO user_messages (SenderID, MessageID, RecipientID) VALUES (?, ?, ?)");
                    $stmt->bind_param("iii", $sender_id, $messageId, $recipient_id);
                    $stmt->execute();
                    $stmt->close();
                    $conn->commit();
                    if ($smsResult['success']) $sent_ok++; else $sent_fail++;
                }
                $conn->close();
                if ($sent_ok > 0) {
                    $message = 'Отправлено: ' . $sent_ok . (($sent_fail > 0) ? ', ошибок: ' . $sent_fail : '');
                    logSystemAction('user', 'sms_send', 'Рассылка: ' . $sent_ok . ' получателей');
                }
                if ($sent_fail > 0 && $sent_ok == 0) {
                    $error = 'Не удалось отправить SMS: ' . ($smsResult['message'] ?? 'Ошибка');
                }
            } catch (Exception $e) {
                if (isset($conn)) { $conn->close(); }
                $error = 'Ошибка отправки: ' . $e->getMessage();
                logSystemAction('user', 'sms_error', $e->getMessage());
            }
        }
        $isAjax = !empty($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => empty($error), 'message' => $error ?: ($message ?? 'Готово')]);
            exit;
        }
    }
}

// Обработка AJAX для шаблонов SMS (только для привязанных предприятий)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $templateAction = $_POST['action'];
    if (in_array($templateAction, ['get_sms_templates', 'get_sms_template', 'add_sms_template', 'update_sms_template', 'delete_sms_template'], true)) {
        header('Content-Type: application/json; charset=utf-8');
        $userCompanies = getCompaniesForUser($user['id']);
        $allowedCompanyIds = array_column($userCompanies, 'CompanyID');
        $selectedCompanyId = getSelectedCompany();

        function ensureSmsTemplatesTableUser($conn) {
            $conn->query("CREATE TABLE IF NOT EXISTS sms_templates (
                TemplateID INT AUTO_INCREMENT PRIMARY KEY,
                CompanyID INT NOT NULL,
                UserID INT UNSIGNED NULL,
                TemplateName VARCHAR(255) NOT NULL,
                TemplateText TEXT NOT NULL,
                CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (CompanyID) REFERENCES companies(CompanyID) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $r = @$conn->query("SHOW COLUMNS FROM sms_templates LIKE 'UserID'");
            if ($r && $r->num_rows == 0) {
                @$conn->query("ALTER TABLE sms_templates ADD COLUMN UserID INT UNSIGNED NULL AFTER CompanyID");
            }
        }

        $currentUserId = (int)($user['id'] ?? 0);

        if ($templateAction === 'get_sms_templates') {
            $templates = [];
            $conn = connectToDatabase();
            ensureSmsTemplatesTableUser($conn);
            $stmt = $conn->prepare("SELECT TemplateID, CompanyID, TemplateName, TemplateText, CreatedAt, UpdatedAt FROM sms_templates WHERE UserID = ? ORDER BY TemplateID DESC");
            $stmt->bind_param("i", $currentUserId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) { $templates[] = $row; }
            $stmt->close();
            $conn->close();
            echo json_encode(['success' => true, 'data' => $templates, 'companies' => $userCompanies]);
            exit;
        }

        if ($templateAction === 'get_sms_template') {
            $id = intval($_POST['id'] ?? 0);
            $companyId = isset($_POST['company_id']) ? intval($_POST['company_id']) : $selectedCompanyId;
            $template = null;
            if ($id > 0 && $companyId && in_array((int)$companyId, $allowedCompanyIds, true)) {
                $conn = connectToDatabase();
                ensureSmsTemplatesTableUser($conn);
                $stmt = $conn->prepare("SELECT TemplateID, CompanyID, TemplateName, TemplateText, CreatedAt, UpdatedAt FROM sms_templates WHERE TemplateID = ? AND CompanyID = ? AND UserID = ?");
                $stmt->bind_param("iii", $id, $companyId, $currentUserId);
                $stmt->execute();
                $template = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $conn->close();
            }
            echo json_encode(['success' => true, 'data' => $template]);
            exit;
        }

        if ($templateAction === 'add_sms_template') {
            $templateName = trim($_POST['template_name'] ?? '');
            $templateText = trim($_POST['template_text'] ?? '');
            $companyIds = isset($_POST['company_ids']) ? (is_array($_POST['company_ids']) ? $_POST['company_ids'] : []) : [];
            $companyIds = array_map('intval', $companyIds);
            $companyIds = array_filter($companyIds, function ($id) use ($allowedCompanyIds) { return $id > 0 && in_array($id, $allowedCompanyIds, true); });
            $companyIds = array_values(array_unique($companyIds));
            if (empty($templateName)) { echo json_encode(['success' => false, 'message' => 'Введите название шаблона']); exit; }
            if (empty($templateText)) { echo json_encode(['success' => false, 'message' => 'Введите текст шаблона']); exit; }
            if (empty($companyIds)) { echo json_encode(['success' => false, 'message' => 'Выберите хотя бы одно предприятие']); exit; }
            $conn = connectToDatabase();
            ensureSmsTemplatesTableUser($conn);
            $stmt = $conn->prepare("INSERT INTO sms_templates (CompanyID, UserID, TemplateName, TemplateText) VALUES (?, ?, ?, ?)");
            $added = 0;
            foreach ($companyIds as $cid) {
                $stmt->bind_param("iiss", $cid, $currentUserId, $templateName, $templateText);
                if ($stmt->execute()) $added++;
            }
            $stmt->close();
            $conn->close();
            logSystemAction('user', 'sms_template_add', 'Добавлен шаблон: ' . $templateName);
            echo json_encode(['success' => true, 'message' => 'Шаблон добавлен на ' . $added . ' предприятий']);
            exit;
        }

        if ($templateAction === 'update_sms_template') {
            $templateId = intval($_POST['template_id'] ?? 0);
            $companyId = isset($_POST['company_id']) ? intval($_POST['company_id']) : $selectedCompanyId;
            if ($templateId <= 0 || !$companyId || !in_array((int)$companyId, $allowedCompanyIds, true)) {
                echo json_encode(['success' => false, 'message' => 'Некорректные данные']); exit;
            }
            $templateName = trim($_POST['template_name'] ?? '');
            $templateText = trim($_POST['template_text'] ?? '');
            if (empty($templateName) || empty($templateText)) {
                echo json_encode(['success' => false, 'message' => 'Заполните название и текст']); exit;
            }
            $conn = connectToDatabase();
            ensureSmsTemplatesTableUser($conn);
            $stmt = $conn->prepare("UPDATE sms_templates SET TemplateName = ?, TemplateText = ? WHERE TemplateID = ? AND CompanyID = ? AND UserID = ?");
            $stmt->bind_param("ssiii", $templateName, $templateText, $templateId, $companyId, $currentUserId);
            $ok = $stmt->execute();
            $stmt->close();
            $conn->close();
            echo json_encode($ok ? ['success' => true, 'message' => 'Шаблон обновлен'] : ['success' => false, 'message' => 'Ошибка обновления']);
            exit;
        }

        if ($templateAction === 'delete_sms_template') {
            $templateId = intval($_POST['id'] ?? 0);
            $companyId = isset($_POST['company_id']) ? intval($_POST['company_id']) : $selectedCompanyId;
            if ($templateId <= 0 || !$companyId || !in_array((int)$companyId, $allowedCompanyIds, true)) {
                echo json_encode(['success' => false, 'message' => 'Нет прав']); exit;
            }
            $conn = connectToDatabase();
            ensureSmsTemplatesTableUser($conn);
            $stmt = $conn->prepare("DELETE FROM sms_templates WHERE TemplateID = ? AND CompanyID = ? AND UserID = ?");
            $stmt->bind_param("iii", $templateId, $companyId, $currentUserId);
            $ok = $stmt->execute();
            $stmt->close();
            $conn->close();
            echo json_encode($ok ? ['success' => true, 'message' => 'Шаблон удален'] : ['success' => false, 'message' => 'Ошибка удаления']);
            exit;
        }
    }

    // Чекбокс-шаблоны (только чтение, для выбранного предприятия)
    if ($_POST['action'] === 'get_checkbox_templates') {
        header('Content-Type: application/json; charset=utf-8');
        $selectedCompanyId = getSelectedCompany();
        $userCompanies = getCompaniesForUser($user['id']);
        $allowedCompanyIds = array_column($userCompanies, 'CompanyID');
        $templates = [];
        if ($selectedCompanyId && in_array((int)$selectedCompanyId, $allowedCompanyIds, true)) {
            $conn = connectToDatabase();
            $conn->query("CREATE TABLE IF NOT EXISTS sms_checkbox_templates (
                CheckboxTemplateID INT AUTO_INCREMENT PRIMARY KEY,
                CompanyID INT NOT NULL,
                TemplateName VARCHAR(255) NOT NULL,
                TemplateData TEXT NOT NULL,
                CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY CompanyID (CompanyID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $stmt = $conn->prepare("SELECT CheckboxTemplateID, CompanyID, TemplateName, TemplateData FROM sms_checkbox_templates WHERE CompanyID = ? ORDER BY TemplateName");
            $stmt->bind_param("i", $selectedCompanyId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $templates[] = $row;
            }
            $stmt->close();
            $conn->close();
        }
        echo json_encode(['success' => true, 'data' => $templates]);
        exit;
    }

    // Чекбокс-шаблоны: обновление (пользователь может редактировать и сохранять)
    if ($_POST['action'] === 'update_checkbox_template') {
        header('Content-Type: application/json; charset=utf-8');
        $selectedCompanyId = getSelectedCompany();
        $userCompanies = getCompaniesForUser($user['id']);
        $allowedCompanyIds = array_column($userCompanies, 'CompanyID');
        if (!$selectedCompanyId || !in_array((int)$selectedCompanyId, $allowedCompanyIds, true)) {
            echo json_encode(['success' => false, 'message' => 'Нет прав на предприятие']); exit;
        }
        $templateId = (int)($_POST['template_id'] ?? 0);
        $templateName = trim((string)($_POST['template_name'] ?? ''));
        $templateDataRaw = (string)($_POST['template_data'] ?? '');
        if ($templateId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Некорректный шаблон']); exit;
        }
        if ($templateName === '' || mb_strlen($templateName) > 255) {
            echo json_encode(['success' => false, 'message' => 'Введите корректное название (до 255 символов)']); exit;
        }
        $decoded = json_decode($templateDataRaw, true);
        if (!is_array($decoded)) {
            echo json_encode(['success' => false, 'message' => 'Некорректные данные шаблона']); exit;
        }
        $cols = $decoded['columns'] ?? null;
        $rows = $decoded['rows'] ?? null;
        if (!is_array($cols) || count($cols) < 1 || !is_array($rows)) {
            echo json_encode(['success' => false, 'message' => 'Шаблон должен содержать columns и rows']); exit;
        }
        // Нормализация, чтобы не улетали объекты/мусор
        $normCols = [];
        foreach ($cols as $c) { $normCols[] = (string)$c; }
        $normRows = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $label = (string)($r['label'] ?? '');
            $values = $r['values'] ?? [];
            if (!is_array($values)) $values = [];
            $normVals = [];
            foreach ($values as $v) { $normVals[] = (string)$v; }
            $normRows[] = ['label' => $label, 'values' => $normVals];
        }
        $final = json_encode(['columns' => $normCols, 'rows' => $normRows], JSON_UNESCAPED_UNICODE);
        if ($final === false) {
            echo json_encode(['success' => false, 'message' => 'Ошибка кодирования шаблона']); exit;
        }
        $conn = connectToDatabase();
        $conn->query("CREATE TABLE IF NOT EXISTS sms_checkbox_templates (
            CheckboxTemplateID INT AUTO_INCREMENT PRIMARY KEY,
            CompanyID INT NOT NULL,
            TemplateName VARCHAR(255) NOT NULL,
            TemplateData TEXT NOT NULL,
            CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
            UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY CompanyID (CompanyID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $stmt = $conn->prepare("UPDATE sms_checkbox_templates SET TemplateName = ?, TemplateData = ? WHERE CheckboxTemplateID = ? AND CompanyID = ?");
        $stmt->bind_param("ssii", $templateName, $final, $templateId, $selectedCompanyId);
        $ok = $stmt->execute();
        $stmt->close();
        $conn->close();
        echo json_encode($ok ? ['success' => true, 'message' => 'Чекбокс-шаблон обновлён'] : ['success' => false, 'message' => 'Ошибка сохранения']);
        exit;
    }
}

// Получаем список получателей SMS для выбора (только пользователи привязанного предприятия — для рассылки)
$conn = connectToDatabase();
$recipients = [];
$messages = [];
$conversations = [];
$feedbackAdmins = [];
try {
    $current_username = $user['username'] ?? '';
    $selectedCompanyId = getSelectedCompany();
    if ($selectedCompanyId) {
        $companyId = (int)$selectedCompanyId;
        $stmt = $conn->prepare("SELECT u.Username FROM users u INNER JOIN user_companies uc ON u.UserID = uc.UserID WHERE uc.CompanyID = ? AND u.Username != ?");
        $stmt->bind_param("is", $companyId, $current_username);
        $stmt->execute();
        $usernames = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) { $usernames[] = $conn->real_escape_string($row['Username']); }
        $stmt->close();
        $nameList = empty($usernames) ? "''" : "'" . implode("','", $usernames) . "'";
        $result = $conn->query("
            SELECT r.RecipientID, r.FullName, r.PhoneNumber, g.GroupName, 
                   CASE WHEN u.UserID IS NOT NULL THEN u.Role ELSE 'recipient' END as UserRole
            FROM recipients r 
            LEFT JOIN groups g ON r.GroupID = g.GroupID 
            LEFT JOIN users u ON r.FullName = u.Username
            WHERE r.FullName != '$current_username'
              AND r.FullName IN ($nameList)
              AND (u.Role IS NULL OR u.Role <> 'admin')
            ORDER BY r.FullName
        ");
    } else {
        $result = $conn->query("
            SELECT r.RecipientID, r.FullName, r.PhoneNumber, g.GroupName, 
                   CASE WHEN u.UserID IS NOT NULL THEN u.Role ELSE 'recipient' END as UserRole
            FROM recipients r 
            LEFT JOIN groups g ON r.GroupID = g.GroupID 
            LEFT JOIN users u ON r.FullName = u.Username
            WHERE r.FullName != '$current_username'
              AND (u.Role IS NULL OR u.Role <> 'admin')
            ORDER BY r.FullName
        ");
    }
    while ($row = $result->fetch_assoc()) {
        $recipients[] = $row;
    }

    // Список доступных групп получателей (берём из таблицы groups)
    $recipientGroups = [];
    $groupsResult = $conn->query("SELECT GroupName FROM groups ORDER BY GroupName");
    if ($groupsResult) {
        while ($gRow = $groupsResult->fetch_assoc()) {
            if (!empty($gRow['GroupName'])) {
                $recipientGroups[] = $gRow['GroupName'];
            }
        }
    }

    // Список администраторов для формы обратной связи (без SMS)
    $feedbackAdmins = [];
    $admRes = $conn->query("
        SELECT u.UserID, u.Username, r.RecipientID
        FROM users u
        LEFT JOIN recipients r ON r.FullName = u.Username
        WHERE u.Role = 'admin' AND u.Status = 'active'
        ORDER BY u.Username
    ");
    if ($admRes) {
        while ($admRow = $admRes->fetch_assoc()) {
            if (!empty($admRow['UserID']) && !empty($admRow['RecipientID'])) {
                $feedbackAdmins[] = [
                    'user_id' => (int)$admRow['UserID'],
                    'username' => $admRow['Username'],
                ];
            }
        }
    }
    
    // Получаем отправленные и полученные пользователем сообщения отдельно
    $current_user_id = (int)($user['id'] ?? 0);
    $current_username = $user['username'] ?? '';
    
    $msg_filter = isset($_GET['msg_filter']) ? $_GET['msg_filter'] : 'all';
    $date_cond = '';
    if ($msg_filter === 'day') $date_cond = ' AND um.SentDate >= DATE_SUB(NOW(), INTERVAL 1 DAY)';
    elseif ($msg_filter === 'week') $date_cond = ' AND um.SentDate >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
    elseif ($msg_filter === 'month') $date_cond = ' AND um.SentDate >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
    $sent_messages = [];
    $result = $conn->query("
        SELECT um.UserMessageID, um.SentDate, um.ReadStatus, um.ReadDate,
               m.Text, m.MessageID,
               r.RecipientID, r.FullName as ContactName, r.PhoneNumber,
               g.GroupName,
               'sent' as MessageType,
               u_sender.Username as SenderName,
               u_recipient.Username as RecipientName,
               ml.LogID as MessageLogID,
               ml.Status as DeliveryStatus,
               ml.Provider as Provider,
               ml.ProviderSmsId as ProviderSmsId,
               ml.ProviderSmsGroupId as ProviderSmsGroupId,
               ml.ProviderStatusText as ProviderStatusText,
               ml.UpdatedAt as StatusUpdatedAt
        FROM user_messages um
        JOIN messages m ON um.MessageID = m.MessageID
        JOIN recipients r ON um.RecipientID = r.RecipientID
        LEFT JOIN groups g ON r.GroupID = g.GroupID
        LEFT JOIN users u_sender ON um.SenderID = u_sender.UserID
        LEFT JOIN users u_recipient ON r.FullName = u_recipient.Username
        LEFT JOIN (
            SELECT t1.*
            FROM messagelogs t1
            INNER JOIN (
                SELECT MessageID, RecipientID, MAX(LogID) as MaxLogID
                FROM messagelogs
                GROUP BY MessageID, RecipientID
            ) t2 ON t1.LogID = t2.MaxLogID
        ) ml ON ml.MessageID = m.MessageID AND ml.RecipientID = r.RecipientID
        WHERE um.SenderID = $current_user_id $date_cond
        ORDER BY um.SentDate DESC 
        LIMIT 100
    ");
    while ($row = $result->fetch_assoc()) {
        $sent_messages[] = $row;
    }
    $received_messages = [];
    $result = $conn->query("
        SELECT um.UserMessageID, um.SentDate, um.ReadStatus, um.ReadDate,
               m.Text, m.MessageID,
               u_sender.Username as ContactName, r.PhoneNumber,
               g.GroupName,
               'received' as MessageType,
               u_sender.Username as SenderName,
               r.FullName as RecipientName,
               rs.RecipientID as SenderRecipientID
        FROM user_messages um
        JOIN messages m ON um.MessageID = m.MessageID
        JOIN recipients r ON um.RecipientID = r.RecipientID
        LEFT JOIN groups g ON r.GroupID = g.GroupID
        LEFT JOIN users u_sender ON um.SenderID = u_sender.UserID
        LEFT JOIN recipients rs ON rs.FullName = u_sender.Username
        WHERE r.FullName = '$current_username' $date_cond
        ORDER BY um.SentDate DESC 
        LIMIT 100
    ");
    
    while ($row = $result->fetch_assoc()) {
        $received_messages[] = $row;
    }
    
    // Строим список диалогов: контакт + последнее сообщение + все сообщения для чата
    $all_msgs = [];
    foreach ($sent_messages as $m) {
        $all_msgs[] = [
            'contact' => $m['ContactName'],
            'recipient_id' => (int)$m['RecipientID'],
            'text' => $m['Text'],
            'date' => $m['SentDate'],
            'type' => 'sent',
            'UserMessageID' => $m['UserMessageID'],
            'ReadStatus' => $m['ReadStatus'],
        ];
    }
    foreach ($received_messages as $m) {
        $all_msgs[] = [
            'contact' => $m['ContactName'],
            'recipient_id' => isset($m['SenderRecipientID']) ? (int)$m['SenderRecipientID'] : 0,
            'text' => $m['Text'],
            'date' => $m['SentDate'],
            'type' => 'received',
            'UserMessageID' => $m['UserMessageID'],
            'ReadStatus' => $m['ReadStatus'],
        ];
    }
    usort($all_msgs, function($a, $b) { return strtotime($b['date']) - strtotime($a['date']); });
    $conversations = [];
    foreach ($all_msgs as $m) {
        $key = $m['contact'];
        if (!isset($conversations[$key])) {
            $conversations[$key] = [
                'contact' => $m['contact'],
                'recipient_id' => $m['recipient_id'],
                'last_text' => $m['text'],
                'last_date' => $m['date'],
                'messages' => [],
            ];
        }
        $conversations[$key]['messages'][] = $m;
    }
    foreach ($conversations as $k => $conv) {
        usort($conversations[$k]['messages'], function($a, $b) { return strtotime($a['date']) - strtotime($b['date']); });
    }
    $conversations = array_values($conversations);
} catch (Exception $e) {
    $error = 'Ошибка загрузки данных: ' . $e->getMessage();
    $conversations = [];
}
$conn->close();
$userCompaniesForTemplates = getCompaniesForUser($user['id']);
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
<body class="app-shell text-gray-800 min-h-screen flex flex-col" style="font-family: 'Inter', sans-serif;">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>.hide-scrollbar::-webkit-scrollbar{display:none}.hide-scrollbar{-ms-overflow-style:none;scrollbar-width:none}.active-tab{color:#10b981}.modal-view{display:none;position:fixed;z-index:1000;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.5)}.modal-view .modal-content{background:#fff;margin:5% auto;padding:0;border-radius:20px;max-width:600px}.modal-view .modal-header{background:linear-gradient(135deg,#10b981,#059669);color:#fff;padding:16px 20px;border-radius:20px 20px 0 0;display:flex;justify-content:space-between;align-items:center}.modal-view .close{color:#fff;font-size:24px;cursor:pointer}.btn-mobile{background:#10b981;color:#fff;padding:10px 20px;border:none;border-radius:10px;font-weight:600;cursor:pointer}.btn-secondary-mobile{background:#6b7280;color:#fff;padding:10px 20px;border:none;border-radius:10px;font-weight:600;cursor:pointer}.user-tabs .tab-content{display:none}.user-tabs .tab-content.active{display:block}.user-tabs .tab{cursor:pointer;padding:10px 16px;border:none;background:transparent;color:#6b7280;font-weight:500;border-bottom:2px solid transparent}.user-tabs .tab:hover{color:#10b981}.user-tabs .tab.active{color:#10b981;border-bottom-color:#10b981}</style>

    <?php include 'navigation.php'; ?>

    <div class="container" style="padding-top: 20px; padding-bottom: 24px;">
        <?php if ($message): ?><div class="mb-4 p-3 bg-green-100 text-green-800 rounded-lg text-sm"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg text-sm"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div class="card user-tabs" style="overflow: visible;">
            <div class="tabs" style="display: flex; flex-wrap: wrap; gap: 4px; border-bottom: 1px solid #e5e7eb; margin: -30px -30px 20px -30px; padding: 0 30px; background: #f9fafb;">
                <button type="button" class="tab active" onclick="switchTab('dashboard')" id="tab-dashboard">📊 Главная</button>
                <button type="button" class="tab text-gray-400" onclick="switchTab('messages')" id="tab-messages">💬 Отправление СМС</button>
                <button type="button" class="tab text-gray-400" onclick="switchTab('templates')" id="tab-templates">📄 Шаблоны</button>
                <button type="button" class="tab text-gray-400" onclick="switchTab('settings')" id="tab-settings">⚙️ Настройки</button>
            </div>

    <main class="flex-1 overflow-y-auto hide-scrollbar space-y-4" id="main-content">
        <!-- VIEW: Главная -->
        <div id="view-dashboard" class="tab-content active space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100">
                    <div class="text-gray-400 text-xs font-medium uppercase">Отправлено</div>
                    <div class="text-2xl font-bold text-emerald-600 mt-1"><?php echo count($sent_messages); ?></div>
                </div>
                <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100">
                    <div class="text-gray-400 text-xs font-medium uppercase">Входящие</div>
                    <div class="text-2xl font-bold text-blue-600 mt-1"><?php echo count($received_messages); ?></div>
                </div>
                <div class="col-span-2">
                    <form method="POST" class="bg-white p-4 rounded-xl shadow-sm border border-gray-100">
                        <input type="hidden" name="action" value="sync_users">
                        <button type="submit" class="w-full flex items-center justify-center gap-2 py-3 bg-gray-50 rounded-lg text-emerald-600 font-medium hover:bg-emerald-50">🔄 Синхронизировать</button>
                    </form>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="p-4 border-b border-gray-50"><h3 class="font-semibold text-gray-700">Последние сообщения</h3></div>
                <div class="divide-y divide-gray-50">
                    <?php $recent = array_merge(array_slice($received_messages, 0, 3), array_slice($sent_messages, 0, 3)); usort($recent, function($a,$b){ return strtotime($b['SentDate'])-strtotime($a['SentDate']); }); $recent = array_slice($recent, 0, 5); ?>
                    <?php if (empty($recent)): ?><div class="p-4 text-gray-500 text-sm">Нет сообщений</div>
                    <?php else: foreach ($recent as $msg): $contact = $msg['MessageType']==='sent' ? $msg['ContactName'] : $msg['SenderName']; ?>
                        <div class="p-4 flex gap-3 cursor-pointer hover:bg-gray-50" onclick="openMessageModal(<?php echo htmlspecialchars(json_encode($msg), ENT_QUOTES, 'UTF-8'); ?>)">
                            <div class="w-10 h-10 rounded-full flex-shrink-0 flex items-center justify-center <?php echo $msg['MessageType']==='sent'?'bg-green-100 text-green-600':'bg-blue-100 text-blue-600'; ?>"><?php echo $msg['MessageType']==='sent'?'📤':'📥'; ?></div>
                            <div class="flex-1 min-w-0">
                                <div class="flex justify-between"><p class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($contact); ?></p><span class="text-xs text-gray-400"><?php echo date('d.m H:i', strtotime($msg['SentDate'])); ?></span></div>
                                <p class="text-sm text-gray-500 truncate mt-0.5"><?php echo htmlspecialchars(mb_substr($msg['Text'],0,50)); ?></p>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="p-4 border-b border-gray-50">
                    <h3 class="font-semibold text-gray-700">Обратная связь с администратором</h3>
                    <p class="text-xs text-gray-500 mt-1">Сообщение уйдёт выбранному администратору в раздел обратной связи, без отправки СМС.</p>
                </div>
                <form id="userFeedbackForm" class="p-4 space-y-3">
                    <textarea id="userFeedbackMessage" class="w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-sm" rows="3" maxlength="1000" placeholder="Опишите проблему или пожелание..."></textarea>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Адресат</label>
                        <select id="userFeedbackAdmin" class="w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <?php if (!empty($feedbackAdmins)): ?>
                                <?php foreach ($feedbackAdmins as $adm): ?>
                                    <option value="<?php echo (int)$adm['user_id']; ?>">
                                        <?php echo htmlspecialchars($adm['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="0">Нет доступных администраторов</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-xs text-gray-400" id="userFeedbackCounter">0 / 1000</span>
                        <button type="submit" class="btn-mobile">Отправить администратору</button>
                    </div>
                    <div id="userFeedbackStatus" class="text-xs mt-1 hidden"></div>
                </form>
            </div>
        </div>

        <!-- VIEW: Сообщения (Полученные + диалоги) -->
        <div id="view-messages" class="tab-content flex flex-col h-full" style="min-height: 0; display: none;">
            <div class="mb-4">
                <!-- Полученные сообщения (с фильтром по времени) -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-3 border-b border-gray-100 font-semibold text-gray-700 flex flex-wrap items-center gap-2">
                        <span>📥</span> Отправленные сообщения
                        <span class="text-sm text-gray-500 font-normal">Период:</span>
                        <select id="userMsgFilter" class="border border-gray-200 rounded-lg px-2 py-1 text-sm ml-auto">
                            <option value="all" <?php echo ($msg_filter ?? '') === 'all' ? 'selected' : ''; ?>>Все</option>
                            <option value="day" <?php echo ($msg_filter ?? '') === 'day' ? 'selected' : ''; ?>>24 часа</option>
                            <option value="week" <?php echo ($msg_filter ?? '') === 'week' ? 'selected' : ''; ?>>7 дней</option>
                            <option value="month" <?php echo ($msg_filter ?? '') === 'month' ? 'selected' : ''; ?>>30 дней</option>
                        </select>
                    </div>
                    <div id="userReceivedMessagesBlock" class="divide-y divide-gray-50 max-h-48 overflow-y-auto">
                        <?php if (empty($received_messages)): ?>
                            <div class="p-3 text-gray-500 text-sm">Нет полученных</div>
                        <?php else: foreach (array_slice($received_messages, 0, 10) as $msg): ?>
                            <div class="p-3 cursor-pointer hover:bg-gray-50 text-sm" onclick="openMessageModal(<?php echo htmlspecialchars(json_encode($msg), ENT_QUOTES, 'UTF-8'); ?>)">
                                <div class="font-medium text-gray-800 truncate"><?php echo htmlspecialchars($msg['SenderName']); ?></div>
                                <div class="text-gray-500 truncate"><?php $t = $msg['Text']; echo htmlspecialchars(mb_strlen($t) > 40 ? mb_substr($t, 0, 40) . '...' : $t); ?></div>
                                <div class="text-xs text-gray-400 mt-1"><?php echo date('d.m H:i', strtotime($msg['SentDate'])); ?></div>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
            <!-- Список диалогов -->
            <div id="msgConversationList" class="flex-1 overflow-y-auto space-y-0">
                <button type="button" onclick="toggleSendModal()" class="w-full py-3 px-4 flex items-center gap-3 bg-emerald-600 text-white rounded-xl font-medium mb-3 hover:bg-emerald-700">
                    <span class="text-xl">✉️</span> Новое сообщение
                </button>
                <div id="msgConversationListItems">
                <?php if (empty($conversations)): ?>
                    <div class="bg-white rounded-xl p-6 text-center text-gray-500 text-sm">Нет диалогов. Нажмите «Новое сообщение» или кнопку + внизу.</div>
                <?php else: ?>
                    <?php foreach ($conversations as $conv): ?>
                        <div class="msg-conv-item flex items-center gap-3 py-3 px-4 bg-white rounded-xl mb-2 cursor-pointer hover:bg-gray-50 border border-gray-100" 
                             data-contact="<?php echo htmlspecialchars($conv['contact']); ?>" 
                             data-recipient-id="<?php echo (int)$conv['recipient_id']; ?>"
                             data-messages="<?php echo htmlspecialchars(json_encode($conv['messages']), ENT_QUOTES, 'UTF-8'); ?>"
                             onclick="openChat(this)">
                            <div class="w-12 h-12 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-lg flex-shrink-0">💬</div>
                            <div class="flex-1 min-w-0">
                                <p class="font-semibold text-gray-800 truncate"><?php echo htmlspecialchars($conv['contact']); ?></p>
                                <p class="text-sm text-gray-500 truncate"><?php echo htmlspecialchars(mb_substr($conv['last_text'], 0, 45)); ?></p>
                            </div>
                            <span class="text-xs text-gray-400 flex-shrink-0"><?php echo date('d.m H:i', strtotime($conv['last_date'])); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
            </div>
            <!-- Экран чата (скрыт по умолчанию) -->
            <div id="msgChatView" class="hidden flex flex-col bg-gray-100" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 30;">
                <div class="bg-white border-b border-gray-200 px-4 py-3 flex items-center gap-2 flex-shrink-0">
                    <button type="button" onclick="closeChat()" class="p-2 -ml-2 text-gray-600 hover:bg-gray-100 rounded-lg">←</button>
                    <span id="chatContactName" class="font-semibold text-gray-800 flex-1 truncate"></span>
                </div>
                <div id="chatMessageList" class="flex-1 overflow-y-auto p-4 space-y-3" style="min-height: 200px;"></div>
            </div>
        </div>

        <!-- VIEW: Шаблоны -->
        <div id="view-templates" class="tab-content space-y-4" style="display: none;">
            <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100">
                <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Название</label>
                <input type="text" id="userTemplateName" class="w-full bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 mb-3" placeholder="Название шаблона">
                <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Текст</label>
                <textarea id="userTemplateText" class="w-full bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 mb-3" rows="3" placeholder="Текст шаблона..."></textarea>
                <div id="userTemplateCompaniesBlock" class="mb-3">
                    <p class="text-xs text-gray-500 mb-2">Добавить на предприятия:</p>
                    <div id="userTemplateCompaniesCheckboxes"><?php foreach ($userCompaniesForTemplates as $c): ?><label class="flex items-center gap-2 py-1"><input type="checkbox" name="user_template_company_cb" value="<?php echo (int)$c['CompanyID']; ?>"> <span class="text-sm"><?php echo htmlspecialchars($c['CompanyName']); ?></span></label><?php endforeach; ?><?php if (empty($userCompaniesForTemplates)): ?><p class="text-sm text-gray-500">Нет привязанных предприятий.</p><?php endif; ?></div>
                </div>
                <div class="flex gap-2">
                    <button type="button" onclick="userSaveSmsTemplate()" id="userSaveTemplateBtn" class="btn-mobile flex-1">💾 Сохранить</button>
                    <button type="button" onclick="userClearTemplateForm()" id="userClearTemplateBtn" class="btn-secondary-mobile" style="display:none">Отмена</button>
                </div>
                <div id="userTemplateStatusMessage" class="mt-2 text-sm hidden"></div>
            </div>
            <h3 class="font-semibold text-gray-700">Текстовые шаблоны предприятия</h3>
            <div id="userSmsTemplatesTable"><div class="text-gray-500 py-4">Загрузка...</div></div>
        </div>

        <!-- VIEW: Настройки -->
        <div id="view-settings" class="tab-content space-y-4" style="display: none;">
            <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100">
                <p class="text-sm text-gray-600 mb-2">Предприятие: <strong><?php echo htmlspecialchars(getSelectedCompanyName() ?: '—'); ?></strong></p>
                <a href="choose_company.php" class="block w-full py-3 text-center bg-emerald-600 text-white rounded-xl font-medium">Сменить предприятие</a>
            </div>
            <form method="POST" class="bg-white p-4 rounded-xl shadow-sm border border-gray-100"><input type="hidden" name="action" value="sync_users"><button type="submit" class="w-full py-3 bg-gray-100 text-gray-700 rounded-xl font-medium">🔄 Синхронизировать пользователей</button></form>
        </div>
    </main>
        </div>
    </div>

    <div id="sendSmsModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-black/50" onclick="toggleSendModal()"></div>
        <div class="absolute bottom-0 left-0 right-0 max-h-[90vh] overflow-y-auto bg-white rounded-t-2xl p-6 shadow-2xl">
            <div class="w-12 h-1 bg-gray-300 rounded-full mx-auto mb-4"></div>
            <h3 class="text-xl font-bold text-gray-800 mb-4">Рассылка СМС</h3>
            <form method="POST" id="sendSmsForm">
                <input type="hidden" name="action" value="send_message">
                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Группа получателей</label>
                        <select id="userSendGroupFilter" class="w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="">Все получатели</option>
                            <?php foreach ($recipientGroups as $gName): ?>
                                <option value="<?php echo htmlspecialchars($gName); ?>"><?php echo htmlspecialchars($gName); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-400 mt-1">Можно быстро выбрать всех из конкретной группы (например, IT отдел).</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Роль</label>
                        <select id="userSendRoleFilter" class="w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-sm">
                            <option value="">Все роли</option>
                            <option value="user">Пользователь</option>
                            <option value="recipient">Получатель</option>
                        </select>
                        <p class="text-xs text-gray-400 mt-1">Можно выбрать всех пользователей или только получателей.</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Получатели (можно несколько)</label>
                        <div class="border border-gray-200 rounded-lg p-3 max-h-40 overflow-y-auto bg-gray-50">
                            <?php foreach ($recipients as $r): ?>
                            <label class="flex items-center gap-2 py-1.5 cursor-pointer"
                                   data-group="<?php echo htmlspecialchars($r['GroupName'] ?? ''); ?>"
                                   data-role="<?php echo htmlspecialchars($r['UserRole'] ?? 'recipient'); ?>">
                                <input type="checkbox" name="recipients[]" value="<?php echo (int)$r['RecipientID']; ?>">
                                <span><?php echo htmlspecialchars($r['FullName']); ?></span>
                                <?php if (!empty($r['GroupName'])): ?>
                                    <span class="text-gray-500 text-sm">— <?php echo htmlspecialchars($r['GroupName']); ?></span>
                                <?php endif; ?>
                            </label>
                            <?php endforeach; ?>
                            <?php if (empty($recipients)): ?><p class="text-gray-500 text-sm">Нет получателей по предприятию. Синхронизируйте пользователей.</p><?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Текст</label>
                        <button type="button" onclick="openUserTemplatesModal()" class="w-full mb-2 py-2 px-3 bg-gray-100 border border-gray-200 rounded-lg text-left text-sm text-gray-600 hover:bg-gray-200">📄 Выбрать шаблон</button>
                        <textarea name="message_text" id="message_text" rows="4" maxlength="600" required class="w-full bg-gray-50 border border-gray-200 rounded-lg px-4 py-3" placeholder="Введите текст..."></textarea>
                        <div class="flex justify-between mt-1"><span class="text-xs text-gray-400" id="charCounter">0 / 600</span></div>
                    </div>
                    <button type="submit" class="w-full bg-emerald-600 text-white font-bold py-4 rounded-xl">Отправить рассылку</button>
                </div>
            </form>
        </div>
    </div>

    <div id="userTemplatesModal" class="fixed inset-0 z-[60] hidden">
        <div class="absolute inset-0 bg-black/50" onclick="closeUserTemplatesModal()"></div>
        <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-full max-w-lg max-h-[85vh] overflow-hidden bg-white rounded-2xl shadow-2xl flex flex-col">
            <div class="p-4 border-b flex justify-between items-center"><h3 id="userTemplatesModalTitle" class="font-bold text-gray-800">Выберите шаблон</h3><span class="cursor-pointer text-2xl text-gray-500" onclick="closeUserTemplatesModal()">&times;</span></div>
            <div id="userTemplatesList" class="p-4 overflow-y-auto flex-1"><div class="text-gray-500">Загрузка...</div></div>
            <div id="userCheckboxTemplatePanel" class="hidden p-4 overflow-y-auto flex-1">
                <button type="button" onclick="userBackFromCheckboxTemplate()" class="mb-3 text-sm text-emerald-600 hover:underline">&larr; Назад к списку</button>
                <p class="text-sm text-gray-600 mb-3">Отметьте чекбоксами нужные данные для вставки в сообщение:</p>
                <div class="mb-3">
                    <label class="block text-xs font-medium text-gray-500 uppercase mb-1">Название шаблона</label>
                    <input type="text" id="userCheckboxTemplateName" class="w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-sm" maxlength="255" placeholder="Название">
                    <div id="userCheckboxTemplateStatus" class="text-xs mt-1 hidden"></div>
                </div>
                <div id="userCheckboxTemplateTableWrap" class="overflow-x-auto border border-gray-200 rounded-lg mb-4"></div>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" onclick="userInsertCheckboxTemplateSelected()" class="w-full py-3 bg-emerald-600 text-white font-medium rounded-xl">Вставить выбранное</button>
                    <button type="button" id="userCheckboxEditBtn" onclick="userToggleCheckboxTemplateEdit()" class="w-full py-3 bg-gray-100 text-gray-800 font-medium rounded-xl border border-gray-200">Редактировать</button>
                </div>
                <button type="button" id="userCheckboxSaveBtn" onclick="userSaveCheckboxTemplate()" class="w-full py-3 mt-2 bg-blue-600 text-white font-medium rounded-xl hidden">Сохранить изменения</button>
            </div>
        </div>
    </div>

    <div id="messageModal" class="modal-view">
        <div class="modal-content mx-4">
            <div class="modal-header"><h3 id="modalTitle">Сообщение</h3><span class="close" onclick="closeModal()">&times;</span></div>
            <div class="p-4">
                <div class="mb-4"><span id="modalMessageType" class="text-sm font-medium"></span> <span id="modalMessageDate" class="text-sm text-gray-500"></span></div>
                <div id="modalMessageText" class="text-gray-800 mb-4 p-3 bg-gray-50 rounded-lg"></div>
                <div class="space-y-2 text-sm">
                    <div><span class="text-gray-500">Контакт:</span> <span id="modalContact"></span></div>
                    <div id="modalDeliveryStatusWrap" style="display:none;">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <span class="text-gray-500">Статус доставки:</span>
                                <span id="modalDeliveryStatus" class="font-medium"></span>
                                <div id="modalDeliveryStatusDetails" class="text-xs text-gray-500 mt-1"></div>
                            </div>
                            <button id="modalRefreshStatusBtn" type="button" onclick="refreshDeliveryStatusFromProvider()" class="px-3 py-2 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700" style="display:none;">
                                Проверить статус доставки
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="p-4 border-t"><button type="button" onclick="closeModal()" class="btn-secondary-mobile w-full">Закрыть</button></div>
        </div>
    </div>

    <div class="hidden">
            <!-- Полученные сообщения (старая версия - скрыто) -->
            <div class="card">
                <h2>📥 Отправленные сообщения</h2>
                
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
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Вторая строка с отправленными сообщениями -->
        <div class="main-content">
            <!-- Отправленные сообщения -->
            

            <!-- Пустая карточка для баланса -->
            
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const textarea = document.getElementById('message_text');
            if (textarea) {
                textarea.addEventListener('input', function() {
                    var c = document.getElementById('charCounter');
                    if (c) c.textContent = this.value.length + ' / 600';
                });
            }
            window.onclick = function(event) {
                if (event.target.id === 'messageModal') closeModal();
            };
            document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });
            loadUserSmsTemplates();
            var userMsgFilterEl = document.getElementById('userMsgFilter');
            if (userMsgFilterEl) {
                userMsgFilterEl.addEventListener('change', function() {
                    var val = this.value;
                    history.replaceState(null, '', 'user.php?msg_filter=' + encodeURIComponent(val));
                    applyUserMsgFilter(val);
                });
            }
            // Выбор группы/роли получателей в модальном окне рассылки
            var groupFilterEl = document.getElementById('userSendGroupFilter');
            var roleFilterEl = document.getElementById('userSendRoleFilter');

            function applySendFilters() {
                var selectedGroup = groupFilterEl ? (groupFilterEl.value || '') : '';
                var selectedRole = roleFilterEl ? (roleFilterEl.value || '') : '';
                var container = document.querySelector('#sendSmsForm .border.border-gray-200.rounded-lg.p-3');
                if (!container) return;
                var labels = container.querySelectorAll('label[data-group][data-role]');
                labels.forEach(function (label) {
                    var cb = label.querySelector('input[type="checkbox"]');
                    if (!cb) return;
                    var gName = label.getAttribute('data-group') || '';
                    var rName = label.getAttribute('data-role') || '';

                    var matchGroup = !selectedGroup || gName === selectedGroup;
                    var matchRole = !selectedRole || rName === selectedRole;

                    cb.checked = matchGroup && matchRole;
                });
            }

            if (groupFilterEl) {
                groupFilterEl.addEventListener('change', function () {
                    applySendFilters();
                });
            }
            if (roleFilterEl) {
                roleFilterEl.addEventListener('change', function () {
                    applySendFilters();
                });
            }

            // Обратная связь с администратором
            var fbForm = document.getElementById('userFeedbackForm');
            var fbText = document.getElementById('userFeedbackMessage');
            var fbCounter = document.getElementById('userFeedbackCounter');
            var fbStatus = document.getElementById('userFeedbackStatus');
            if (fbText && fbCounter) {
                fbText.addEventListener('input', function () {
                    var len = this.value.length;
                    fbCounter.textContent = len + ' / 1000';
                });
            }
            if (fbForm && fbText && fbStatus) {
                fbForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var msg = fbText.value.trim();
                    if (!msg) {
                        fbStatus.textContent = 'Введите текст сообщения';
                        fbStatus.className = 'text-xs mt-1 text-red-600';
                        fbStatus.classList.remove('hidden');
                        return;
                    }
                    var fd = new FormData();
                    fd.append('action', 'send_feedback');
                    var adminSel = document.getElementById('userFeedbackAdmin');
                    var adminUserId = adminSel ? parseInt(adminSel.value, 10) || 0 : 0;
                    fd.append('admin_user_id', adminUserId);
                    fd.append('message', msg);
                    fbStatus.textContent = 'Отправка...';
                    fbStatus.className = 'text-xs mt-1 text-gray-500';
                    fbStatus.classList.remove('hidden');
                    fetch('user.php', { method: 'POST', body: fd })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (data.success) {
                                fbStatus.textContent = data.message || 'Сообщение отправлено администратору';
                                fbStatus.className = 'text-xs mt-1 text-emerald-600';
                                fbText.value = '';
                                fbCounter.textContent = '0 / 1000';
                            } else {
                                fbStatus.textContent = data.message || 'Не удалось отправить сообщение';
                                fbStatus.className = 'text-xs mt-1 text-red-600';
                            }
                        })
                        .catch(function () {
                            fbStatus.textContent = 'Ошибка отправки сообщения';
                            fbStatus.className = 'text-xs mt-1 text-red-600';
                        });
                });
            }
        });

        function escapeAttr(s) {
            if (s == null) return '';
            return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }
        function applyUserMsgFilter(filterValue) {
            var fd = new FormData();
            fd.append('action', 'get_messages_filter');
            fd.append('msg_filter', filterValue);
            var receivedBlock = document.getElementById('userReceivedMessagesBlock');
            var listItems = document.getElementById('msgConversationListItems');
            if (receivedBlock) receivedBlock.innerHTML = '<div class="p-3 text-gray-500 text-sm">Загрузка...</div>';
            if (listItems) listItems.innerHTML = '<div class="p-4 text-gray-500 text-sm">Загрузка...</div>';
            fetch('user.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) return;
                    renderUserMessagesView(data);
                })
                .catch(function() {
                    if (receivedBlock) receivedBlock.innerHTML = '<div class="p-3 text-red-500 text-sm">Ошибка загрузки</div>';
                    if (listItems) listItems.innerHTML = '<div class="p-4 text-red-500 text-sm">Ошибка загрузки</div>';
                });
        }
        function renderUserMessagesView(data) {
            var received = data.received_messages || [];
            var conversations = data.conversations || [];
            var receivedBlock = document.getElementById('userReceivedMessagesBlock');
            var listItems = document.getElementById('msgConversationListItems');
            if (receivedBlock) {
                if (received.length === 0) {
                    receivedBlock.innerHTML = '<div class="p-3 text-gray-500 text-sm">Нет полученных</div>';
                } else {
                    receivedBlock.innerHTML = received.slice(0, 10).map(function(msg) {
                        var dataAttr = escapeAttr(JSON.stringify(msg));
                        var sender = escapeAttr(msg.SenderName || msg.ContactName);
                        var text = escapeAttr((msg.Text || '').length > 40 ? (msg.Text || '').substring(0, 40) + '...' : (msg.Text || ''));
                        var dateStr = msg.SentDate ? new Date(msg.SentDate).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '';
                        return '<div class="p-3 cursor-pointer hover:bg-gray-50 text-sm" data-msg="' + dataAttr + '" onclick="openMessageModal(JSON.parse(this.getAttribute(\'data-msg\')))"><div class="font-medium text-gray-800 truncate">' + sender + '</div><div class="text-gray-500 truncate">' + text + '</div><div class="text-xs text-gray-400 mt-1">' + dateStr + '</div></div>';
                    }).join('');
                }
            }
            if (listItems) {
                if (conversations.length === 0) {
                    listItems.innerHTML = '<div class="bg-white rounded-xl p-6 text-center text-gray-500 text-sm">Нет диалогов. Нажмите «Новое сообщение».</div>';
                } else {
                    listItems.innerHTML = conversations.map(function(conv) {
                        var contact = escapeAttr(conv.contact);
                        var lastText = escapeAttr((conv.last_text || '').substring(0, 45));
                        var lastDate = conv.last_date ? new Date(conv.last_date).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '';
                        var messagesJson = escapeAttr(JSON.stringify(conv.messages || []));
                        return '<div class="msg-conv-item flex items-center gap-3 py-3 px-4 bg-white rounded-xl mb-2 cursor-pointer hover:bg-gray-50 border border-gray-100" data-contact="' + contact + '" data-recipient-id="' + (conv.recipient_id || '') + '" data-messages="' + messagesJson + '" onclick="openChat(this)"><div class="w-12 h-12 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-lg flex-shrink-0">💬</div><div class="flex-1 min-w-0"><p class="font-semibold text-gray-800 truncate">' + contact + '</p><p class="text-sm text-gray-500 truncate">' + lastText + '</p></div><span class="text-xs text-gray-400 flex-shrink-0">' + lastDate + '</span></div>';
                    }).join('');
                }
            }
        }

        function switchTab(name) {
            ['dashboard','messages','templates','settings'].forEach(function(t) {
                var el = document.getElementById('view-' + t);
                if (el) { el.classList.remove('active'); el.style.display = 'none'; }
            });
            var view = document.getElementById('view-' + name);
            if (view) { view.classList.add('active'); view.style.display = view.id === 'view-messages' ? 'flex' : 'block'; }
            document.querySelectorAll('.user-tabs .tab').forEach(function(btn) {
                btn.classList.remove('active');
                btn.classList.add('text-gray-400');
            });
            var tabBtn = document.getElementById('tab-' + name);
            if (tabBtn) { tabBtn.classList.add('active'); tabBtn.classList.remove('text-gray-400'); }
        }

        function toggleSendModal() {
            var m = document.getElementById('sendSmsModal');
            m.classList.toggle('hidden');
        }
        function openUserTemplatesModal() {
            var modal = document.getElementById('userTemplatesModal');
            var list = document.getElementById('userTemplatesList');
            var panel = document.getElementById('userCheckboxTemplatePanel');
            if (!modal || !list) return;
            list.innerHTML = '<div class="text-gray-500">Загрузка...</div>';
            if (panel) panel.classList.add('hidden');
            list.classList.remove('hidden');
            modal.classList.remove('hidden');
            var fdSms = new FormData();
            fdSms.append('action', 'get_sms_templates');
            var fdCb = new FormData();
            fdCb.append('action', 'get_checkbox_templates');
            Promise.all([
                fetch('user.php', { method: 'POST', body: fdSms }).then(function(r) { return r.json(); }),
                fetch('user.php', { method: 'POST', body: fdCb }).then(function(r) { return r.json(); })
            ]).then(function(results) {
                var smsData = results[0];
                var cbData = results[1];
                var html = '';
                if (smsData.success && smsData.data && smsData.data.length) {
                    html += '<p class="text-xs font-medium text-gray-500 uppercase mb-2">Текстовые шаблоны</p>';
                    html += smsData.data.map(function(t) {
                        var name = (t.TemplateName || '').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                        var preview = (t.TemplateText || '').substring(0, 60).replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                        var dataText = (t.TemplateText || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                        return '<div class="p-3 border border-gray-200 rounded-lg mb-2 cursor-pointer hover:bg-emerald-50" data-text="' + dataText + '" onclick="userSelectTemplate(this)"><div class="font-medium text-gray-800">' + name + '</div><div class="text-sm text-gray-500 truncate">' + preview + '</div></div>';
                    }).join('');
                }
                if (cbData.success && cbData.data && cbData.data.length) {
                    window._userCheckboxTemplates = cbData.data;
                    if (html) html += '<p class="text-xs font-medium text-gray-500 uppercase mt-4 mb-2">Чекбокс-шаблоны</p>';
                    html += cbData.data.map(function(t, idx) {
                        var name = (t.TemplateName || '').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                        return '<div class="p-3 border border-emerald-200 rounded-lg mb-2 cursor-pointer hover:bg-emerald-50 bg-emerald-50/50" data-type="checkbox" data-idx="' + idx + '" onclick="userSelectCheckboxTemplate(this)"><div class="font-medium text-gray-800">' + name + '</div><div class="text-sm text-gray-500">Таблица — выберите нужные значения</div></div>';
                    }).join('');
                } else { window._userCheckboxTemplates = []; }
                if (!html) list.innerHTML = '<p class="text-gray-500">Нет шаблонов. Администратор создаёт чекбокс-шаблоны во вкладке Создание шаблона SMS.</p>';
                else list.innerHTML = html;
            }).catch(function() { list.innerHTML = '<p class="text-red-500">Ошибка загрузки</p>'; });
        }
        function userSelectCheckboxTemplate(el) {
            var idx = parseInt(el.getAttribute('data-idx'), 10);
            var templates = window._userCheckboxTemplates || [];
            var t = templates[idx];
            if (!t || !t.TemplateData) return;
            var parsed;
            try { parsed = JSON.parse(t.TemplateData); } catch (e) { return; }
            window._userCurrentCheckboxTemplate = parsed;
            window._userCurrentCheckboxTemplateMeta = {
                template_id: parseInt(t.CheckboxTemplateID, 10) || 0,
                company_id: parseInt(t.CompanyID, 10) || 0,
                template_name: t.TemplateName || ''
            };
            window._userCheckboxEditMode = false;
            var wrap = document.getElementById('userCheckboxTemplateTableWrap');
            var panel = document.getElementById('userCheckboxTemplatePanel');
            var list = document.getElementById('userTemplatesList');
            if (!wrap || !panel || !list) return;
            var cols = parsed.columns || [];
            var rows = parsed.rows || [];
            var nameInput = document.getElementById('userCheckboxTemplateName');
            if (nameInput) nameInput.value = (t.TemplateName || '');
            var editBtn = document.getElementById('userCheckboxEditBtn');
            var saveBtn = document.getElementById('userCheckboxSaveBtn');
            if (editBtn) editBtn.textContent = 'Редактировать';
            if (saveBtn) saveBtn.classList.add('hidden');
            var tbl = '';
            if (cols.length > 1) {
                // Панель выбора столбцов, которые будут вставляться в текст
                tbl += '<div class="mb-2 text-sm text-gray-600">Выберите столбцы, которые нужно вставлять вместе со строкой:</div>';
                tbl += '<div class="flex flex-wrap gap-2 mb-3">';
                for (var ci = 1; ci < cols.length; ci++) {
                    var cname = (cols[ci] || '').replace(/</g, '&lt;');
                    tbl += '<label class="flex items-center gap-1 text-xs border border-gray-200 rounded-full px-2 py-1 bg-gray-50 cursor-pointer"><input type="checkbox" class="user-cb-col" data-col-index="' + ci + '" checked> ' + cname + '</label>';
                }
                tbl += '</div>';
            }
            tbl += '<table class="w-full text-sm border-collapse"><thead><tr>';
            tbl += '<th class="border border-gray-200 p-2 bg-gray-100 text-left">Выбор</th>';
            cols.forEach(function(c) { tbl += '<th class="border border-gray-200 p-2 bg-gray-100 text-left">' + (c || '').replace(/</g, '&lt;') + '</th>'; });
            tbl += '</tr></thead><tbody>';
            rows.forEach(function(r, ri) {
                var label = r.label || '';
                var vals = r.values || [];
                tbl += '<tr><td class="border border-gray-200 p-2"><label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" class="user-cb-row" data-row="' + ri + '" data-label="' + (label || '').replace(/"/g, '&quot;') + '" data-values=\'' + JSON.stringify(vals).replace(/'/g, '&#39;') + '\'> Строка</label></td>';
                tbl += '<td class="border border-gray-200 p-2">' + (label || '').replace(/</g, '&lt;') + '</td>';
                vals.forEach(function(v) { tbl += '<td class="border border-gray-200 p-2">' + (v || '').replace(/</g, '&lt;') + '</td>'; });
                tbl += '</tr>';
            });
            tbl += '</tbody></table>';
            wrap.innerHTML = tbl;
            list.classList.add('hidden');
            panel.classList.remove('hidden');
        }

        function userSetCheckboxTemplateStatus(msg, type) {
            var el = document.getElementById('userCheckboxTemplateStatus');
            if (!el) return;
            el.textContent = msg || '';
            el.className = 'text-xs mt-1 ' + (type === 'error' ? 'text-red-600' : 'text-emerald-600');
            el.classList.remove('hidden');
            if (!msg) el.classList.add('hidden');
        }

        function userToggleCheckboxTemplateEdit() {
            var wrap = document.getElementById('userCheckboxTemplateTableWrap');
            if (!wrap) return;
            var t = window._userCurrentCheckboxTemplate;
            if (!t) return;
            window._userCheckboxEditMode = !window._userCheckboxEditMode;
            var editBtn = document.getElementById('userCheckboxEditBtn');
            var saveBtn = document.getElementById('userCheckboxSaveBtn');
            if (editBtn) editBtn.textContent = window._userCheckboxEditMode ? 'Отмена' : 'Редактировать';
            if (saveBtn) {
                if (window._userCheckboxEditMode) saveBtn.classList.remove('hidden');
                else saveBtn.classList.add('hidden');
            }
            userSetCheckboxTemplateStatus('', 'success');
            userRenderCheckboxTemplateTable(t, window._userCheckboxEditMode);
        }

        function userRenderCheckboxTemplateTable(parsed, editMode) {
            var wrap = document.getElementById('userCheckboxTemplateTableWrap');
            if (!wrap) return;
            var cols = parsed.columns || [];
            var rows = parsed.rows || [];
            var esc = function(s) { return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); };
            var tbl = '';
            if (cols.length > 1) {
                tbl += '<div class="mb-2 text-sm text-gray-600">Выберите столбцы, которые нужно вставлять вместе со строкой:</div>';
                tbl += '<div class="flex flex-wrap gap-2 mb-3">';
                for (var ci = 1; ci < cols.length; ci++) {
                    var cname = esc(cols[ci]);
                    tbl += '<label class="flex items-center gap-1 text-xs border border-gray-200 rounded-full px-2 py-1 bg-gray-50 cursor-pointer"><input type="checkbox" class="user-cb-col" data-col-index="' + ci + '" checked> ' + cname + '</label>';
                }
                tbl += '</div>';
            }
            tbl += '<table class="w-full text-sm border-collapse"><thead><tr>';
            tbl += '<th class="border border-gray-200 p-2 bg-gray-100 text-left">Выбор</th>';
            cols.forEach(function(c, i) {
                if (!editMode || i === 0) {
                    tbl += '<th class="border border-gray-200 p-2 bg-gray-100 text-left">' + esc(c) + '</th>';
                } else {
                    tbl += '<th class="border border-gray-200 p-2 bg-gray-100 text-left"><input class="w-full bg-white border border-gray-200 rounded px-2 py-1 text-sm user-cb-edit-col" data-col="' + i + '" value="' + esc(c) + '"></th>';
                }
            });
            tbl += '</tr></thead><tbody>';
            rows.forEach(function(r, ri) {
                var label = r.label || '';
                var vals = r.values || [];
                tbl += '<tr><td class="border border-gray-200 p-2"><label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" class="user-cb-row" data-row="' + ri + '"> Строка</label></td>';
                if (editMode) {
                    tbl += '<td class="border border-gray-200 p-2"><input class="w-full bg-white border border-gray-200 rounded px-2 py-1 text-sm user-cb-edit-label" data-row="' + ri + '" value="' + esc(label) + '"></td>';
                } else {
                    tbl += '<td class="border border-gray-200 p-2">' + esc(label) + '</td>';
                }
                vals.forEach(function(v, vi) {
                    if (editMode) {
                        tbl += '<td class="border border-gray-200 p-2"><input class="w-full bg-white border border-gray-200 rounded px-2 py-1 text-sm user-cb-edit-val" data-row="' + ri + '" data-idx="' + vi + '" value="' + esc(v) + '"></td>';
                    } else {
                        tbl += '<td class="border border-gray-200 p-2">' + esc(v) + '</td>';
                    }
                });
                tbl += '</tr>';
            });
            tbl += '</tbody></table>';
            wrap.innerHTML = tbl;
        }

        function userSaveCheckboxTemplate() {
            var meta = window._userCurrentCheckboxTemplateMeta || {};
            var parsed = window._userCurrentCheckboxTemplate;
            if (!meta.template_id || !parsed) return;
            var nameInput = document.getElementById('userCheckboxTemplateName');
            var name = nameInput ? (nameInput.value || '').trim() : '';
            if (!name) { userSetCheckboxTemplateStatus('Введите название шаблона', 'error'); return; }
            // Считываем данные из инпутов (режим редактирования)
            if (window._userCheckboxEditMode) {
                var colInputs = document.querySelectorAll('#userCheckboxTemplateTableWrap .user-cb-edit-col');
                colInputs.forEach(function(inp) {
                    var ci = parseInt(inp.getAttribute('data-col') || '0', 10);
                    if (!isNaN(ci) && ci >= 1) parsed.columns[ci] = inp.value || '';
                });
                var labelInputs = document.querySelectorAll('#userCheckboxTemplateTableWrap .user-cb-edit-label');
                labelInputs.forEach(function(inp) {
                    var ri = parseInt(inp.getAttribute('data-row') || '0', 10);
                    if (!isNaN(ri) && parsed.rows && parsed.rows[ri]) parsed.rows[ri].label = inp.value || '';
                });
                var valInputs = document.querySelectorAll('#userCheckboxTemplateTableWrap .user-cb-edit-val');
                valInputs.forEach(function(inp) {
                    var ri = parseInt(inp.getAttribute('data-row') || '0', 10);
                    var vi = parseInt(inp.getAttribute('data-idx') || '0', 10);
                    if (!isNaN(ri) && !isNaN(vi) && parsed.rows && parsed.rows[ri]) {
                        if (!Array.isArray(parsed.rows[ri].values)) parsed.rows[ri].values = [];
                        parsed.rows[ri].values[vi] = inp.value || '';
                    }
                });
            }
            var fd = new FormData();
            fd.append('action', 'update_checkbox_template');
            fd.append('template_id', String(meta.template_id));
            fd.append('template_name', name);
            fd.append('template_data', JSON.stringify(parsed));
            userSetCheckboxTemplateStatus('Сохранение...', 'success');
            fetch('user.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.success) {
                        userSetCheckboxTemplateStatus(data.message || 'Сохранено', 'success');
                        // Обновим локальный список и выйдем из режима редактирования
                        var templates = window._userCheckboxTemplates || [];
                        templates.forEach(function(t) {
                            if ((parseInt(t.CheckboxTemplateID, 10) || 0) === meta.template_id) {
                                t.TemplateName = name;
                                t.TemplateData = JSON.stringify(parsed);
                            }
                        });
                        window._userCheckboxEditMode = false;
                        var editBtn = document.getElementById('userCheckboxEditBtn');
                        var saveBtn = document.getElementById('userCheckboxSaveBtn');
                        if (editBtn) editBtn.textContent = 'Редактировать';
                        if (saveBtn) saveBtn.classList.add('hidden');
                        userRenderCheckboxTemplateTable(parsed, false);
                    } else {
                        userSetCheckboxTemplateStatus((data && data.message) ? data.message : 'Ошибка сохранения', 'error');
                    }
                })
                .catch(function() { userSetCheckboxTemplateStatus('Ошибка сети при сохранении', 'error'); });
        }
        function userBackFromCheckboxTemplate() {
            var panel = document.getElementById('userCheckboxTemplatePanel');
            var list = document.getElementById('userTemplatesList');
            if (panel) panel.classList.add('hidden');
            if (list) list.classList.remove('hidden');
        }
        function userInsertCheckboxTemplateSelected() {
            var checked = document.querySelectorAll('#userCheckboxTemplateTableWrap .user-cb-row:checked');
            var parts = [];
            var t = window._userCurrentCheckboxTemplate;
            var cols = (t && t.columns) || [];

            // Какие столбцы пользователь выбрал для вставки
            var colCheckboxes = document.querySelectorAll('#userCheckboxTemplateTableWrap .user-cb-col:checked');
            var allowedCols = [];
            colCheckboxes.forEach(function (cb) {
                var idx = parseInt(cb.getAttribute('data-col-index') || '0', 10);
                if (!isNaN(idx) && idx > 0) {
                    allowedCols.push(idx);
                }
            });

            checked.forEach(function(cb) {
                var ri = parseInt(cb.getAttribute('data-row') || '0', 10);
                var rowObj = (t && t.rows && t.rows[ri]) ? t.rows[ri] : null;
                var label = rowObj ? (rowObj.label || '') : '';
                var vals = rowObj ? (rowObj.values || []) : [];
                var items = [];
                if (label) items.push(label);
                (vals || []).forEach(function(v, i) {
                    var colIndex = i + 1; // значения идут после первого столбца (метка)
                    if (!v || !cols[colIndex]) return;
                    if (allowedCols.length && allowedCols.indexOf(colIndex) === -1) return;
                    items.push(cols[colIndex] + '-' + v);
                });
                if (items.length) parts.push(items.join(' '));
            });
            var text = parts.join('; ');
            var ta = document.getElementById('message_text');
            if (ta) {
                var cur = (ta.value || '').trim();
                ta.value = cur.length ? cur + ' ' + text : text;
                var ev = new Event('input');
                ta.dispatchEvent(ev);
            }
            closeUserTemplatesModal();
        }
        function userSelectTemplate(el) {
            if (!el || !el.getAttribute) return;
            var text = (el.getAttribute('data-text') || '').replace(/&quot;/g, '"').replace(/&#39;/g, "'");
            if (!text) return;
            var ta = document.getElementById('message_text');
            if (ta) {
                var cur = (ta.value || '').trim();
                ta.value = cur.length ? cur + ' ' + text : text;
                var ev = new Event('input');
                ta.dispatchEvent(ev);
            }
            closeUserTemplatesModal();
        }
        function closeUserTemplatesModal() {
            var modal = document.getElementById('userTemplatesModal');
            if (modal) modal.classList.add('hidden');
        }

        function openChat(rowEl) {
            var contact = rowEl.getAttribute('data-contact') || '';
            var messagesJson = rowEl.getAttribute('data-messages') || '[]';
            var messages = [];
            try { messages = JSON.parse(messagesJson); } catch (e) {}
            document.getElementById('chatContactName').textContent = contact;
            var list = document.getElementById('chatMessageList');
            list.innerHTML = '';
            messages.forEach(function(m) {
                var isSent = m.type === 'sent';
                var bubble = document.createElement('div');
                bubble.className = 'flex ' + (isSent ? 'justify-end' : 'justify-start');
                var inner = document.createElement('div');
                inner.className = 'max-w-[85%] px-4 py-2 rounded-2xl ' + (isSent ? 'bg-emerald-500 text-white rounded-br-md' : 'bg-white border border-gray-200 text-gray-800 rounded-bl-md');
                inner.textContent = m.text;
                var time = document.createElement('div');
                time.className = 'text-xs mt-1 opacity-80';
                time.textContent = formatDate(m.date);
                inner.appendChild(time);
                bubble.appendChild(inner);
                list.appendChild(bubble);
            });
            list.scrollTop = list.scrollHeight;
            document.getElementById('msgConversationList').classList.add('hidden');
            document.getElementById('msgChatView').classList.remove('hidden');
        }

        function closeChat() {
            document.getElementById('msgChatView').classList.add('hidden');
            document.getElementById('msgConversationList').classList.remove('hidden');
        }

        // Функция открытия модального окна
        let currentModalMessageData = null;

        function openMessageModal(messageData) {
            const modal = document.getElementById('messageModal');
            currentModalMessageData = messageData || null;
            
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

            // Статус доставки (для отправленных)
            const statusWrap = document.getElementById('modalDeliveryStatusWrap');
            const statusText = document.getElementById('modalDeliveryStatus');
            const statusDetails = document.getElementById('modalDeliveryStatusDetails');
            const refreshBtn = document.getElementById('modalRefreshStatusBtn');
            if (statusWrap && statusText && statusDetails && refreshBtn) {
                if (messageData.MessageType === 'sent') {
                    statusWrap.style.display = 'block';
                    statusText.textContent = messageData.DeliveryStatus || '—';
                    statusDetails.textContent = messageData.ProviderStatusText || '';
                    const canRefresh = !!(messageData.MessageLogID && (messageData.ProviderSmsId || messageData.ProviderSmsGroupId) && messageData.Provider === 'beeline_a2p');
                    refreshBtn.style.display = canRefresh ? 'inline-flex' : 'none';
                } else {
                    statusWrap.style.display = 'none';
                }
            }
            
            // Показываем модальное окно
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden'; // Блокируем прокрутку фона
        }

        function refreshDeliveryStatusFromProvider() {
            if (!currentModalMessageData || !currentModalMessageData.MessageLogID) return;
            const btn = document.getElementById('modalRefreshStatusBtn');
            const statusText = document.getElementById('modalDeliveryStatus');
            const statusDetails = document.getElementById('modalDeliveryStatusDetails');
            if (btn) { btn.disabled = true; btn.textContent = 'Проверка...'; }
            const fd = new FormData();
            fd.append('action', 'refresh_sms_status');
            fd.append('log_id', String(currentModalMessageData.MessageLogID));
            fetch('user.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data && data.success) {
                        if (statusText) statusText.textContent = data.status || '—';
                        if (statusDetails) statusDetails.textContent = data.provider_status_text || '';
                        // Обновим данные в текущем объекте, чтобы при повторном открытии было актуально
                        currentModalMessageData.DeliveryStatus = data.status || currentModalMessageData.DeliveryStatus;
                        currentModalMessageData.ProviderStatusText = data.provider_status_text || currentModalMessageData.ProviderStatusText;
                    } else {
                        const msg = (data && data.message) ? data.message : 'Ошибка проверки статуса';
                        if (statusDetails) statusDetails.textContent = msg;
                    }
                })
                .catch(() => {
                    if (statusDetails) statusDetails.textContent = 'Ошибка сети при проверке статуса';
                })
                .finally(() => {
                    if (btn) { btn.disabled = false; btn.textContent = 'Проверить статус доставки'; }
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

        let userSmsTemplates = [];
        let userEditingTemplateId = null;
        let userEditingTemplateCompanyId = null;

        function loadUserSmsTemplates() {
            const fd = new FormData();
            fd.append('action', 'get_sms_templates');
            fetch('user.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    userSmsTemplates = data.data || [];
                    renderUserSmsTemplatesTable();
                } else {
                    document.getElementById('userSmsTemplatesTable').innerHTML = '<p style="color:#666">Нет шаблонов</p>';
                }
            })
            .catch(() => {
                document.getElementById('userSmsTemplatesTable').innerHTML = '<p style="color:#c00">Ошибка загрузки</p>';
            });
        }

        function renderUserSmsTemplatesTable() {
            const container = document.getElementById('userSmsTemplatesTable');
            if (!container) return;
            if (!userSmsTemplates.length) {
                container.innerHTML = '<p style="color:#666">Нет шаблонов для текущего предприятия</p>';
                return;
            }
            let table = '<table class="data-table" style="width:100%; border-collapse: collapse;"><thead><tr><th>Название</th><th>Текст</th><th>Действия</th></tr></thead><tbody>';
            userSmsTemplates.forEach(t => {
                const textPreview = (t.TemplateText || '').length > 50 ? t.TemplateText.substring(0, 50) + '...' : (t.TemplateText || '');
                table += `<tr>
                    <td><strong>${escapeHtmlUser(t.TemplateName)}</strong></td>
                    <td>${escapeHtmlUser(textPreview)}</td>
                    <td>
                        <button type="button" class="btn" style="padding:5px 10px; font-size:12px; margin-right:5px;" onclick="userEditSmsTemplate(${t.TemplateID}, ${t.CompanyID})">Редактировать</button>
                        <button type="button" class="btn btn-danger" style="padding:5px 10px; font-size:12px;" onclick="userDeleteSmsTemplate(${t.TemplateID}, ${t.CompanyID})">Удалить</button>
                    </td>
                </tr>`;
            });
            table += '</tbody></table>';
            container.innerHTML = table;
        }

        function escapeHtmlUser(s) {
            if (!s) return '';
            const div = document.createElement('div');
            div.textContent = s;
            return div.innerHTML;
        }

        function userSaveSmsTemplate() {
            const name = (document.getElementById('userTemplateName') && document.getElementById('userTemplateName').value) ? document.getElementById('userTemplateName').value.trim() : '';
            const text = (document.getElementById('userTemplateText') && document.getElementById('userTemplateText').value) ? document.getElementById('userTemplateText').value.trim() : '';
            const fd = new FormData();
            fd.append('action', userEditingTemplateId ? 'update_sms_template' : 'add_sms_template');
            fd.append('template_name', name);
            fd.append('template_text', text);
            if (userEditingTemplateId) {
                fd.append('template_id', userEditingTemplateId);
                fd.append('company_id', userEditingTemplateCompanyId || '');
            } else {
                const cbs = document.querySelectorAll('#userTemplateCompaniesCheckboxes input[name=user_template_company_cb]:checked');
                const ids = Array.from(cbs).map(c => c.value);
                if (!ids.length) { showUserTemplateStatus('Выберите хотя бы одно предприятие', 'error'); return; }
                ids.forEach(id => fd.append('company_ids[]', id));
            }
            if (!name) { showUserTemplateStatus('Введите название шаблона', 'error'); return; }
            if (!text) { showUserTemplateStatus('Введите текст шаблона', 'error'); return; }
            fetch('user.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                showUserTemplateStatus(data.message, data.success ? 'success' : 'error');
                if (data.success) { userClearTemplateForm(); loadUserSmsTemplates(); }
            })
            .catch(() => showUserTemplateStatus('Ошибка сети', 'error'));
        }

        function userEditSmsTemplate(id, companyId) {
            const fd = new FormData();
            fd.append('action', 'get_sms_template');
            fd.append('id', id);
            fd.append('company_id', companyId || '');
            fetch('user.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.data) {
                    const t = data.data;
                    document.getElementById('userTemplateName').value = t.TemplateName || '';
                    document.getElementById('userTemplateText').value = t.TemplateText || '';
                    userEditingTemplateId = t.TemplateID;
                    userEditingTemplateCompanyId = t.CompanyID || companyId;
                    document.getElementById('userTemplateCompaniesBlock').style.display = 'none';
                    document.getElementById('userSaveTemplateBtn').textContent = 'Обновить шаблон';
                    document.getElementById('userClearTemplateBtn').style.display = 'inline-block';
                }
            });
        }

        function userDeleteSmsTemplate(id, companyId) {
            if (!confirm('Удалить этот шаблон?')) return;
            const fd = new FormData();
            fd.append('action', 'delete_sms_template');
            fd.append('id', id);
            fd.append('company_id', companyId || '');
            fetch('user.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                showUserTemplateStatus(data.message, data.success ? 'success' : 'error');
                if (data.success) loadUserSmsTemplates();
            });
        }

        function userClearTemplateForm() {
            document.getElementById('userTemplateName').value = '';
            document.getElementById('userTemplateText').value = '';
            userEditingTemplateId = null;
            userEditingTemplateCompanyId = null;
            document.getElementById('userSaveTemplateBtn').textContent = '💾 Сохранить';
            document.getElementById('userClearTemplateBtn').style.display = 'none';
            document.getElementById('userTemplateCompaniesBlock').style.display = 'block';
        }

        function showUserTemplateStatus(msg, type) {
            const el = document.getElementById('userTemplateStatusMessage');
            if (!el) return;
            el.textContent = msg;
            el.className = 'mt-2 text-sm ' + (type === 'error' ? 'text-red-600' : 'text-green-600');
            el.classList.remove('hidden');
            setTimeout(function() { el.classList.add('hidden'); }, 5000);
        }
    </script>
</body>
</html>
