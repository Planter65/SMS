<?php
require_once 'auth.php';
require_once 'sms_providers.php';

// Проверяем авторизацию и права администратора
requireRole('admin');

$user = getCurrentUser();
$message = '';
$error = '';
$groups = fetchGroups(); // Загружаем группы для использования в HTML
// Обработка AJAX запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'add_group':
            $result = addGroup($_POST);
            echo json_encode($result);
            exit;
        case 'add_message':
            $result = addMessage($_POST);
            echo json_encode($result);
            exit;
        case 'delete_group':
            $result = deleteGroup($_POST);
            echo json_encode($result);
            exit;
        case 'get_groups':
            echo json_encode(['success' => true, 'data' => fetchGroups()]);
            exit;
        case 'get_messages':
            echo json_encode(['success' => true, 'data' => fetchMessages()]);
            exit;
        case 'get_message_history':
            echo json_encode(['success' => true, 'data' => fetchMessageHistory()]);
            exit;
        case 'get_statistics':
            echo json_encode(['success' => true, 'data' => fetchStatistics()]);
            exit;
        case 'clear_message_history':
            echo json_encode(clearMessageHistory());
            exit;
        case 'add_user':
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'user';
            $phoneNumber = trim($_POST['phoneNumber'] ?? '');
            if ($username === '' || $password === '') {
                echo json_encode(['success' => false, 'message' => 'Введите имя пользователя и пароль']);
                exit;
            }
            $res = registerUser($username, $password, $role, $phoneNumber);
            if (is_array($res) && !empty($res['success'])) {
                $phoneInfo = !empty($phoneNumber) ? ', телефон: ' . $phoneNumber : '';
                logSystemAction('users', 'add', 'Добавлен пользователь: ' . $username . ', роль: ' . $role . $phoneInfo);
            } else {
                logSystemAction('users', 'add_error', 'Ошибка добавления пользователя: ' . $username);
            }
            echo json_encode($res);
            exit;
        case 'get_users':
            echo json_encode(['success' => true, 'data' => fetchUsers()]);
            exit;
        case 'get_recipients':
            echo json_encode(['success' => true, 'data' => fetchRecipients()]);
            exit;
        case 'delete_user':
            $id = intval($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Некорректный ID пользователя']);
                exit;
            }
            echo json_encode(deleteUserById($id));
            exit;
        case 'update_user':
            $id = intval($_POST['id'] ?? 0);
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'user';
            $status = $_POST['status'] ?? 'active';
            $phoneNumber = trim($_POST['phoneNumber'] ?? '');
            
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Некорректный ID пользователя']);
                exit;
            }
            
            if ($username === '') {
                echo json_encode(['success' => false, 'message' => 'Введите имя пользователя']);
                exit;
            }
            
            echo json_encode(updateUserById($id, $username, $password, $role, $status, $phoneNumber));
            exit;
        case 'get_system_logs':
            echo json_encode(['success' => true, 'data' => fetchSystemLogs()]);
            exit;
        case 'create_db_backup':
            echo json_encode(createDatabaseBackup());
            exit;
        case 'list_db_backups':
            echo json_encode(['success' => true, 'data' => listDatabaseBackups()]);
            exit;
        case 'import_db_backup':
            echo json_encode(importDatabaseBackup());
            exit;
        case 'sync_users':
            $result = syncUsersToRecipients('');
            if ($result['success']) {
                logSystemAction('users', 'sync', $result['message'] ?? 'Синхронизация пользователей завершена');
            }
            echo json_encode($result);
            exit;
        case 'send_user_message':
            echo json_encode(sendUserMessage($_POST));
            exit;
        case 'get_user_messages':
            echo json_encode(['success' => true, 'data' => fetchUserMessages()]);
            exit;
        case 'update_read_status':
            echo json_encode(updateMessageReadStatus($_POST));
            exit;
        case 'clear_system_logs':
            echo json_encode(clearSystemLogs());
            exit;
        case 'add_sms_template':
            echo json_encode(addSmsTemplate($_POST));
            exit;
        case 'update_sms_template':
            echo json_encode(updateSmsTemplate($_POST));
            exit;
        case 'delete_sms_template':
            echo json_encode(deleteSmsTemplate($_POST));
            exit;
        case 'get_sms_templates':
            echo json_encode(['success' => true, 'data' => fetchSmsTemplates()]);
            exit;
        case 'get_sms_template':
            $id = intval($_POST['id'] ?? 0);
            echo json_encode(['success' => true, 'data' => getSmsTemplateById($id)]);
            exit;
    }
}

// Функции для работы с данными (из api.php)

function addGroup($data) {
    if (!isset($data['name'])) {
        return ['success' => false, 'message' => 'Отсутствует название группы'];
    }
    
    $conn = connectToDatabase();
    $stmt = $conn->prepare("INSERT INTO groups (GroupName) VALUES (?)");
    $stmt->bind_param("s", $data['name']);
    
    if ($stmt->execute()) {
        logSystemAction('groups', 'add', 'Добавлена группа: ' . ($data['name'] ?? ''));
        $conn->close();
        return ['success' => true, 'message' => 'Группа добавлена успешно'];
    } else {
        $conn->close();
        return ['success' => false, 'message' => 'Ошибка при добавлении группы'];
    }
}

function addMessage($data) {
    if (!isset($data['text'])) {
        return ['success' => false, 'message' => 'Отсутствует текст сообщения'];
    }
    
    $conn = connectToDatabase();
    $stmt = $conn->prepare("INSERT INTO messages (Text) VALUES (?)");
    $stmt->bind_param("s", $data['text']);
    
    if ($stmt->execute()) {
        logSystemAction('messages', 'add', 'Добавлен шаблон сообщения: ' . mb_substr(($data['text'] ?? ''), 0, 80));
        $conn->close();
        return ['success' => true, 'message' => 'Сообщение добавлено успешно'];
    } else {
        $conn->close();
        return ['success' => false, 'message' => 'Ошибка при добавлении сообщения'];
    }
}


function deleteGroup($data) {
    if (!isset($data['id'])) {
        return ['success' => false, 'message' => 'Отсутствует ID группы'];
    }
    
    $conn = connectToDatabase();
    $stmt = $conn->prepare("DELETE FROM groups WHERE GroupID = ?");
    $stmt->bind_param("i", $data['id']);
    
    if ($stmt->execute()) {
        logSystemAction('groups', 'delete', 'Удалена группа ID=' . ($data['id'] ?? ''));
        $conn->close();
        return ['success' => true, 'message' => 'Группа удалена успешно'];
    } else {
        $conn->close();
        return ['success' => false, 'message' => 'Ошибка при удалении группы'];
    }
}


// Вспомогательные выборки для вкладок (группы, сообщения, статистика, пользователи)

function fetchGroups() {
    $conn = connectToDatabase();
    $sql = "SELECT GroupID, GroupName FROM groups ORDER BY GroupID DESC";
    $result = $conn->query($sql);
    $rows = [];
    while ($row = $result->fetch_assoc()) { $rows[] = $row; }
    $conn->close();
    return $rows;
}

function fetchMessages() {
    $conn = connectToDatabase();
    $sql = "SELECT m.MessageID, m.Text FROM messages m ORDER BY m.MessageID DESC";
    $result = $conn->query($sql);
    $rows = [];
    while ($row = $result->fetch_assoc()) { $rows[] = $row; }
    $conn->close();
    return $rows;
}

function fetchMessageHistory() {
    $conn = connectToDatabase();
    $sql = "
        SELECT ml.LogID,
               m.Text AS MessageText,
               r.FullName AS RecipientName,
               g.GroupName AS GroupName,
               ml.SentDate
        FROM messagelogs ml
        JOIN messages m   ON m.MessageID = ml.MessageID
        JOIN recipients r ON r.RecipientID = ml.RecipientID
        LEFT JOIN groups g ON g.GroupID = r.GroupID
        ORDER BY ml.SentDate DESC, ml.LogID DESC
        LIMIT 500
    ";
    $result = $conn->query($sql);
    $rows = [];
    while ($row = $result->fetch_assoc()) { $rows[] = $row; }
    $conn->close();
    return $rows;
}

function clearMessageHistory() {
    try {
        $conn = connectToDatabase();
        $conn->query("DELETE FROM messagelogs");
        $conn->close();
        logSystemAction('messages', 'history_clear', 'История сообщений очищена');
        return ['success' => true, 'message' => 'История сообщений очищена'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Ошибка очистки истории: ' . $e->getMessage()];
    }
}

function fetchStatistics() {
    $conn = connectToDatabase();
    $stats = [];
    $stats['totalRecipients'] = intval($conn->query("SELECT COUNT(*) AS c FROM recipients")->fetch_assoc()['c'] ?? 0);
    $stats['totalGroups'] = intval($conn->query("SELECT COUNT(*) AS c FROM groups")->fetch_assoc()['c'] ?? 0);
    $stats['totalMessages'] = intval($conn->query("SELECT COUNT(*) AS c FROM messages")->fetch_assoc()['c'] ?? 0);
    $stats['totalSent'] = intval($conn->query("SELECT COUNT(*) AS c FROM messagelogs")->fetch_assoc()['c'] ?? 0);
    $conn->close();
    return $stats;
}

function fetchUsers() {
    $conn = connectToDatabase();
    $sql = "SELECT UserID, Username, PhoneNumber, Role, Status, CreatedAt FROM users ORDER BY UserID DESC";
    $result = $conn->query($sql);
    $rows = [];
    while ($row = $result->fetch_assoc()) { $rows[] = $row; }
    $conn->close();
    return $rows;
}

function fetchRecipients() {
    $conn = connectToDatabase();
    $sql = "
        SELECT r.RecipientID, r.FullName, r.PhoneNumber, g.GroupName 
        FROM recipients r 
        LEFT JOIN groups g ON r.GroupID = g.GroupID 
        ORDER BY r.FullName
    ";
    $result = $conn->query($sql);
    $rows = [];
    while ($row = $result->fetch_assoc()) { $rows[] = $row; }
    $conn->close();
    return $rows;
}

function deleteUserById($id) {
    $conn = connectToDatabase();
    $stmt = $conn->prepare("DELETE FROM users WHERE UserID = ?");
    $stmt->bind_param("i", $id);
    $ok = $stmt->execute();
    $conn->close();
    if ($ok) { logSystemAction('users', 'delete', 'Удален пользователь ID=' . $id); }
    return ['success' => $ok, 'message' => $ok ? 'Пользователь удален' : 'Не удалось удалить пользователя'];
}

function updateUserById($id, $username, $password, $role, $status, $phoneNumber = '') {
    try {
        $conn = connectToDatabase();
        
        // Проверяем, существует ли пользователь
        $check_stmt = $conn->prepare("SELECT UserID FROM users WHERE UserID = ?");
        $check_stmt->bind_param("i", $id);
        $check_stmt->execute();
        $existing = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        
        if (!$existing) {
            $conn->close();
            return ['success' => false, 'message' => 'Пользователь не найден'];
        }
        
        // Проверяем, не занято ли имя пользователя другим пользователем
        $check_username_stmt = $conn->prepare("SELECT UserID FROM users WHERE Username = ? AND UserID != ?");
        $check_username_stmt->bind_param("si", $username, $id);
        $check_username_stmt->execute();
        $username_exists = $check_username_stmt->get_result()->fetch_assoc();
        $check_username_stmt->close();
        
        if ($username_exists) {
            $conn->close();
            return ['success' => false, 'message' => 'Пользователь с таким именем уже существует'];
        }
        
        // Нормализуем номер телефона, если он указан
        $normalizedPhone = '';
        if (!empty($phoneNumber)) {
            $normalizedPhone = preg_replace('/[^0-9+]/', '', trim($phoneNumber));
            if (!empty($normalizedPhone) && $normalizedPhone[0] !== '+') {
                if (preg_match('/^[78]/', $normalizedPhone)) {
                    $normalizedPhone = '+7' . substr($normalizedPhone, 1);
                } else {
                    $normalizedPhone = '+7' . $normalizedPhone;
                }
            }
        }
        
        // Обновляем пользователя
        if (!empty($password)) {
            // Если пароль указан, обновляем с паролем
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET Username = ?, PasswordHash = ?, Role = ?, Status = ?, PhoneNumber = ? WHERE UserID = ?");
            $stmt->bind_param("sssssi", $username, $hashed_password, $role, $status, $normalizedPhone, $id);
        } else {
            // Если пароль не указан, обновляем без пароля
            $stmt = $conn->prepare("UPDATE users SET Username = ?, Role = ?, Status = ?, PhoneNumber = ? WHERE UserID = ?");
            $stmt->bind_param("ssssi", $username, $role, $status, $normalizedPhone, $id);
        }
        
        $ok = $stmt->execute();
        $stmt->close();
        $conn->close();
        
        if ($ok) {
            $phoneInfo = !empty($normalizedPhone) ? ', телефон: ' . $normalizedPhone : '';
            logSystemAction('users', 'update', 'Обновлен пользователь ID=' . $id . ', имя: ' . $username . ', роль: ' . $role . $phoneInfo);
            return ['success' => true, 'message' => 'Пользователь успешно обновлен'];
        } else {
            return ['success' => false, 'message' => 'Не удалось обновить пользователя'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Ошибка обновления: ' . $e->getMessage()];
    }
}


// Журнал действий системы
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

function logSystemAction($category, $action, $details = '') {
    try {
        $conn = connectToDatabase();
        ensureSystemLogsTable($conn);
        $user = function_exists('getCurrentUser') ? getCurrentUser() : null;
        $performedBy = is_array($user) && isset($user['username']) ? $user['username'] : 'system';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt = $conn->prepare("INSERT INTO system_logs (Category, Action, Details, PerformedBy, IPAddress) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $category, $action, $details, $performedBy, $ip);
        $stmt->execute();
        $conn->close();
    } catch (Exception $e) {
        // no-op to avoid breaking main flow
    }
}

function fetchSystemLogs() {
    $conn = connectToDatabase();
    ensureSystemLogsTable($conn);
    $rows = [];
    $sql = "SELECT LogID, Category, Action, Details, PerformedBy, IPAddress, CreatedAt FROM system_logs ORDER BY LogID DESC LIMIT 500";
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) { $rows[] = $row; }
    }
    $conn->close();
    return $rows;
}

function clearSystemLogs() {
    try {
        $conn = connectToDatabase();
        ensureSystemLogsTable($conn);
        $conn->query("TRUNCATE TABLE system_logs");
        $conn->close();
        logSystemAction('system', 'logs_cleared', 'Журнал действий очищен');
        return ['success' => true, 'message' => 'Журнал действий очищен'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Ошибка очистки журнала: ' . $e->getMessage()];
    }
}

// Бекап базы данных (JSON файлы в папке backups)
function createDatabaseBackup() {
    try {
        $conn = connectToDatabase();
        $data = [];
        $tables = [
            'recipients' => 'SELECT * FROM recipients',
            'groups' => 'SELECT * FROM groups',
            'messages' => 'SELECT * FROM messages',
            'messagelogs' => 'SELECT * FROM messagelogs ORDER BY LogID DESC',
            'users' => 'SELECT UserID, Username, Role, Status, CreatedAt FROM users'
        ];
        // user_feedback (если есть)
        $res = $conn->query("SHOW TABLES LIKE 'user_feedback'");
        if ($res && $res->num_rows > 0) {
            $tables['user_feedback'] = 'SELECT * FROM user_feedback ORDER BY CreatedAt DESC';
        }

        foreach ($tables as $name => $sql) {
            $rows = [];
            if ($result = $conn->query($sql)) {
                while ($row = $result->fetch_assoc()) { $rows[] = $row; }
            }
            $data[$name] = $rows;
        }
        $conn->close();

        $backupDir = __DIR__ . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($backupDir)) { @mkdir($backupDir, 0775, true); }
        $timestamp = date('Y-m-d_H-i-s');
        $fileName = 'backup_' . $timestamp . '.json';
        $filePath = $backupDir . DIRECTORY_SEPARATOR . $fileName;
        $payload = [
            'meta' => [
                'created_at' => date('c'),
                'host' => $_SERVER['HTTP_HOST'] ?? '',
                'script' => $_SERVER['SCRIPT_NAME'] ?? '',
            ],
            'data' => $data
        ];
        $ok = (bool)file_put_contents($filePath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if (!$ok) {
            logSystemAction('backup', 'create_error', 'Не удалось сохранить файл бекапа');
            return ['success' => false, 'message' => 'Не удалось сохранить файл бекапа'];
        }
        logSystemAction('backup', 'create', 'Создан бекап ' . $fileName);
        return ['success' => true, 'message' => 'Бекап создан', 'file' => $fileName];
    } catch (Exception $e) {
        logSystemAction('backup', 'create_error', 'Ошибка бекапа: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Ошибка бекапа: ' . $e->getMessage()];
    }
}

function listDatabaseBackups() {
    $backupDir = __DIR__ . DIRECTORY_SEPARATOR . 'backups';
    $items = [];
    if (is_dir($backupDir)) {
        $files = scandir($backupDir);
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            if (substr($f, -5) !== '.json') continue;
            $full = $backupDir . DIRECTORY_SEPARATOR . $f;
            $items[] = [
                'name' => $f,
                'size' => filesize($full),
                'mtime' => date('Y-m-d H:i:s', filemtime($full)),
                'url' => 'backups/' . $f
            ];
        }
        // newest first
        usort($items, function($a, $b) { return strcmp($b['name'], $a['name']); });
    }
    return $items;
}

function importDatabaseBackup() {
    try {
        // Expect multipart/form-data with file and options
        if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            return ['success' => false, 'message' => 'Файл не получен'];
        }
        $mode = $_POST['mode'] ?? 'append'; // append|replace
        $opts = [
            'groups' => ($_POST['import_groups'] ?? '1') === '1',
            'recipients' => ($_POST['import_recipients'] ?? '1') === '1',
            'messages' => ($_POST['import_messages'] ?? '1') === '1',
            'logs' => ($_POST['import_logs'] ?? '1') === '1',
            'users' => ($_POST['import_users'] ?? '0') === '1',
            'feedback' => ($_POST['import_feedback'] ?? '1') === '1',
        ];

        $raw = file_get_contents($_FILES['file']['tmp_name']);
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return ['success' => false, 'message' => 'Некорректный JSON'];
        }
        $data = $json['data'] ?? $json; // allow plain dump or wrapped

        $conn = connectToDatabase();
        $conn->begin_transaction();
        // Best-effort: relax FKs
        @$conn->query('SET FOREIGN_KEY_CHECKS=0');

        $result = ['inserted' => [], 'skipped' => []];

        // REPLACE mode: clear tables in safe order
        if ($mode === 'replace') {
            if ($opts['logs']) { @$conn->query('DELETE FROM messagelogs'); }
            if ($opts['messages']) { @$conn->query('DELETE FROM messages'); }
            if ($opts['recipients']) { @$conn->query('DELETE FROM recipients'); }
            if ($opts['groups']) { @$conn->query('DELETE FROM groups'); }
            if ($opts['feedback']) { @$conn->query("SHOW TABLES LIKE 'user_feedback'"); $conn->query('DELETE FROM user_feedback'); }
            // Users: do not delete for safety
        }

        // Groups
        if ($opts['groups'] && !empty($data['groups']) && is_array($data['groups'])) {
            $stmt = $conn->prepare('INSERT INTO groups (GroupID, GroupName) VALUES (?, ?)');
            foreach ($data['groups'] as $row) {
                $gid = intval($row['GroupID'] ?? 0);
                $name = strval($row['GroupName'] ?? '');
                if ($gid <= 0 || $name === '') { $result['skipped'][] = ['groups' => $row]; continue; }
                $stmt->bind_param('is', $gid, $name);
                @$stmt->execute();
            }
            $result['inserted']['groups'] = count($data['groups']);
        }

        // Recipients
        if ($opts['recipients'] && !empty($data['recipients']) && is_array($data['recipients'])) {
            $stmt = $conn->prepare('INSERT INTO recipients (RecipientID, PhoneNumber, FullName, GroupID) VALUES (?, ?, ?, ?)');
            foreach ($data['recipients'] as $row) {
                $id = intval($row['RecipientID'] ?? 0);
                $phone = strval($row['PhoneNumber'] ?? '');
                $name = strval($row['FullName'] ?? '');
                $gid = isset($row['GroupID']) && $row['GroupID'] !== '' ? intval($row['GroupID']) : null;
                if ($id <= 0 || $phone === '' || $name === '') { $result['skipped'][] = ['recipients' => $row]; continue; }
                // bind null for group id when needed
                if ($gid === null) {
                    $stmtNull = $conn->prepare('INSERT INTO recipients (RecipientID, PhoneNumber, FullName, GroupID) VALUES (?, ?, ?, NULL)');
                    $stmtNull->bind_param('iss', $id, $phone, $name);
                    @$stmtNull->execute();
                } else {
                    $stmt->bind_param('issi', $id, $phone, $name, $gid);
                    @$stmt->execute();
                }
            }
            $result['inserted']['recipients'] = count($data['recipients']);
        }

        // Messages
        if ($opts['messages'] && !empty($data['messages']) && is_array($data['messages'])) {
            $stmt = $conn->prepare('INSERT INTO messages (MessageID, Text) VALUES (?, ?)');
            foreach ($data['messages'] as $row) {
                $mid = intval($row['MessageID'] ?? 0);
                $text = strval($row['Text'] ?? '');
                if ($mid <= 0 || $text === '') { $result['skipped'][] = ['messages' => $row]; continue; }
                $stmt->bind_param('is', $mid, $text);
                @$stmt->execute();
            }
            $result['inserted']['messages'] = count($data['messages']);
        }

        // Message logs
        if ($opts['logs'] && !empty($data['messagelogs']) && is_array($data['messagelogs'])) {
            $stmt = $conn->prepare('INSERT INTO messagelogs (LogID, MessageID, RecipientID, Status, SentDate) VALUES (?, ?, ?, ?, ?)');
            foreach ($data['messagelogs'] as $row) {
                $lid = intval($row['LogID'] ?? 0);
                $mid = intval($row['MessageID'] ?? 0);
                $rid = intval($row['RecipientID'] ?? 0);
                $status = strval($row['Status'] ?? '');
                $dt = strval($row['SentDate'] ?? '');
                if ($lid <= 0 || $mid <= 0 || $rid <= 0) { $result['skipped'][] = ['messagelogs' => $row]; continue; }
                $stmt->bind_param('iiiss', $lid, $mid, $rid, $status, $dt);
                @$stmt->execute();
            }
            $result['inserted']['messagelogs'] = count($data['messagelogs']);
        }

        // User feedback (optional)
        if ($opts['feedback'] && !empty($data['user_feedback']) && is_array($data['user_feedback'])) {
            $res = $conn->query("SHOW TABLES LIKE 'user_feedback'");
            if ($res && $res->num_rows > 0) {
                $stmt = $conn->prepare('INSERT INTO user_feedback (FeedbackID, Username, Status, Message, CreatedAt) VALUES (?, ?, ?, ?, ?)');
                foreach ($data['user_feedback'] as $row) {
                    $fid = intval($row['FeedbackID'] ?? 0);
                    $un = strval($row['Username'] ?? '');
                    $st = strval($row['Status'] ?? '');
                    $msg = strval($row['Message'] ?? '');
                    $dt = strval($row['CreatedAt'] ?? '');
                    if ($fid <= 0) { $result['skipped'][] = ['user_feedback' => $row]; continue; }
                    $stmt->bind_param('issss', $fid, $un, $st, $msg, $dt);
                    @$stmt->execute();
                }
                $result['inserted']['user_feedback'] = count($data['user_feedback']);
            }
        }

        // Users (append only for safety)
        if ($opts['users'] && !empty($data['users']) && is_array($data['users'])) {
            $stmt = $conn->prepare('INSERT INTO users (UserID, Username, Role, Status, CreatedAt) VALUES (?, ?, ?, ?, ?)');
            foreach ($data['users'] as $row) {
                $uid = intval($row['UserID'] ?? 0);
                $un = strval($row['Username'] ?? '');
                $role = strval($row['Role'] ?? 'user');
                $st = strval($row['Status'] ?? 'active');
                $dt = strval($row['CreatedAt'] ?? date('Y-m-d H:i:s'));
                if ($uid <= 0 || $un === '') { $result['skipped'][] = ['users' => $row]; continue; }
                $stmt->bind_param('issss', $uid, $un, $role, $st, $dt);
                @$stmt->execute();
            }
            $result['inserted']['users'] = count($data['users']);
        }

        @$conn->query('SET FOREIGN_KEY_CHECKS=1');
        $conn->commit();

        logSystemAction('backup', 'import', 'Импорт завершен, режим: ' . $mode);
        return ['success' => true, 'message' => 'Импорт завершен', 'result' => $result];
    } catch (Exception $e) {
        if (isset($conn)) { $conn->rollback(); }
        logSystemAction('backup', 'import_error', 'Ошибка импорта: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Ошибка импорта: ' . $e->getMessage()];
    }
}

// Функции для работы с пользовательскими сообщениями
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

function sendUserMessage($data) {
    $recipients = $data['recipients'] ?? ($data['recipient_id'] ?? []);
    if (!is_array($recipients)) { $recipients = [$recipients]; }
    $recipients = array_filter(array_map('intval', $recipients));
    $message_text = $data['message_text'] ?? '';
    $user = getCurrentUser();

    if (empty($recipients)) {
        return ['success' => false, 'message' => 'Выберите получателей'];
    }
    if (trim($message_text) === '') {
        return ['success' => false, 'message' => 'Текст сообщения не может быть пустым'];
    }
    
    try {
        $conn = connectToDatabase();
        $conn->begin_transaction();
        
        ensureUserMessagesTable($conn);

        $stmt = $conn->prepare("INSERT INTO messages (Text) VALUES (?)");
        $stmt->bind_param("s", $message_text);
        $stmt->execute();
        $messageId = $conn->insert_id;
        $stmt->close();

        $sender_id = intval($user['id'] ?? 0);
        $preview = mb_substr((string)$message_text, 0, 120);
        $sent = 0; $errors = 0;

        foreach ($recipients as $recipient_id) {
            $stmt = $conn->prepare("SELECT PhoneNumber FROM recipients WHERE RecipientID = ?");
            $stmt->bind_param("i", $recipient_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $recipient = $result->fetch_assoc();
            $stmt->close();
            
            if (!$recipient || empty($recipient['PhoneNumber'])) {
                $errors++;
                continue;
            }
            
            // Добавляем название отправителя в конец сообщения
            $companyName = getSelectedCompanyName();
            $messageWithSender = $message_text;
            if (!empty($companyName)) {
                $senderSuffix = ' ' . $companyName;
                // Проверяем, не превышает ли сообщение лимит в 160 символов
                if (mb_strlen($message_text . $senderSuffix) <= 160) {
                    $messageWithSender = $message_text . $senderSuffix;
                }
            }
            
            $smsResult = sendSms($recipient['PhoneNumber'], $messageWithSender);
            $smsStatus = $smsResult['success'] ? 'Доставлено' : ($smsResult['status'] ?? 'Ошибка');

            $stmt = $conn->prepare("INSERT INTO messagelogs (MessageID, RecipientID, Status, SentDate) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param("iis", $messageId, $recipient_id, $smsStatus);
            $stmt->execute();
            $stmt->close();
            
            $stmt = $conn->prepare("INSERT INTO user_messages (SenderID, MessageID, RecipientID) VALUES (?, ?, ?)");
            $stmt->bind_param("iii", $sender_id, $messageId, $recipient_id);
            $ok = $stmt->execute();
            $stmt->close();

            if ($ok && $smsResult['success']) {
                $sent++;
            } else {
                $errors++;
            }
        }

        $conn->commit();
        $logMessage = 'SMS: ' . $preview . '; получателей: ' . count($recipients) . '; отправлено: ' . $sent . '; ошибки: ' . $errors;
        logSystemAction('user', 'sms_send', $logMessage);
        $conn->close();
        return ['success' => true, 'message' => "Отправлено: $sent, ошибок: $errors"];
    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollback();
            $conn->close();
        }
        logSystemAction('user', 'sms_error', 'Исключение: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Ошибка отправки SMS: ' . $e->getMessage()];
    }
}

function fetchUserMessages() {
    $conn = connectToDatabase();
    $user = getCurrentUser();
    // Исправляем: getCurrentUser() возвращает 'id', а не 'UserID'
    $current_user_id = intval($user['id'] ?? 0);
    $current_username = $user['username'] ?? '';
    
    // Получаем отправленные сообщения (только для текущего пользователя)
    $sent_messages = [];
    $stmt = $conn->prepare("
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
        WHERE um.SenderID = ?
        ORDER BY um.SentDate DESC 
        LIMIT 15
    ");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $sent_messages[] = $row;
    }
    $stmt->close();
    
    // Получаем полученные сообщения (только для текущего пользователя)
    $received_messages = [];
    $stmt = $conn->prepare("
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
        WHERE r.FullName = ?
        ORDER BY um.SentDate DESC 
        LIMIT 15
    ");
    $stmt->bind_param("s", $current_username);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $received_messages[] = $row;
    }
    $stmt->close();
    
    $conn->close();
    
    return [
        'sent' => $sent_messages,
        'received' => $received_messages
    ];
}

function updateMessageReadStatus($data) {
    if (!isset($data['user_message_id']) || !isset($data['read_status'])) {
        return ['success' => false, 'message' => 'Отсутствуют обязательные параметры'];
    }
    
    $user_message_id = $data['user_message_id'];
    $new_status = $data['read_status'];
    
    try {
        $conn = connectToDatabase();
        
        // Проверяем, что пользователь имеет право изменять статус этого сообщения
        $user = getCurrentUser();
        $current_user_id = intval($user['id'] ?? 0);
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
            return ['success' => false, 'message' => 'Сообщение не найдено'];
        }
        
        // Проверяем права: пользователь может изменять статус если он отправитель или получатель
        $is_sender = ($message_data['SenderID'] == $current_user_id);
        $is_recipient = ($message_data['RecipientName'] == $current_username);
        
        if (!$is_sender && !$is_recipient) {
            return ['success' => false, 'message' => 'У вас нет прав для изменения статуса этого сообщения'];
        }
        
        $read_date = ($new_status === 'read') ? 'NOW()' : 'NULL';
        $stmt = $conn->prepare("UPDATE user_messages SET ReadStatus = ?, ReadDate = $read_date WHERE UserMessageID = ?");
        $stmt->bind_param("si", $new_status, $user_message_id);
        
        if ($stmt->execute()) {
            logSystemAction('user', 'read_status_update', "Статус изменен на: $new_status для сообщения ID: $user_message_id");
            $stmt->close();
            $conn->close();
            return ['success' => true, 'message' => 'Статус прочтения обновлен!'];
        } else {
            $stmt->close();
            $conn->close();
            return ['success' => false, 'message' => 'Не удалось обновить статус прочтения'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Ошибка обновления статуса: ' . $e->getMessage()];
    }
}

// Функции для работы с шаблонами SMS
function ensureSmsTemplatesTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS sms_templates (
        TemplateID INT AUTO_INCREMENT PRIMARY KEY,
        CompanyID INT NOT NULL,
        TemplateName VARCHAR(255) NOT NULL,
        TemplateText TEXT NOT NULL,
        CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (CompanyID) REFERENCES companies(CompanyID) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function addSmsTemplate($data) {
    try {
        $companyId = getSelectedCompany();
        if (!$companyId) {
            return ['success' => false, 'message' => 'Предприятие не выбрано'];
        }
        
        $templateName = trim($data['template_name'] ?? '');
        $templateText = trim($data['template_text'] ?? '');
        
        if (empty($templateName)) {
            return ['success' => false, 'message' => 'Введите название шаблона'];
        }
        
        if (empty($templateText)) {
            return ['success' => false, 'message' => 'Введите текст шаблона'];
        }
        
        $conn = connectToDatabase();
        ensureSmsTemplatesTable($conn);
        
        $stmt = $conn->prepare("INSERT INTO sms_templates (CompanyID, TemplateName, TemplateText) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $companyId, $templateName, $templateText);
        
        if ($stmt->execute()) {
            logSystemAction('sms_templates', 'add', 'Добавлен шаблон SMS: ' . $templateName);
            $stmt->close();
            $conn->close();
            return ['success' => true, 'message' => 'Шаблон успешно добавлен'];
        } else {
            $stmt->close();
            $conn->close();
            return ['success' => false, 'message' => 'Ошибка при добавлении шаблона'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()];
    }
}

function updateSmsTemplate($data) {
    try {
        $templateId = intval($data['template_id'] ?? 0);
        $companyId = getSelectedCompany();
        
        if ($templateId <= 0) {
            return ['success' => false, 'message' => 'Некорректный ID шаблона'];
        }
        
        if (!$companyId) {
            return ['success' => false, 'message' => 'Предприятие не выбрано'];
        }
        
        $templateName = trim($data['template_name'] ?? '');
        $templateText = trim($data['template_text'] ?? '');
        
        if (empty($templateName)) {
            return ['success' => false, 'message' => 'Введите название шаблона'];
        }
        
        if (empty($templateText)) {
            return ['success' => false, 'message' => 'Введите текст шаблона'];
        }
        
        $conn = connectToDatabase();
        ensureSmsTemplatesTable($conn);
        
        // Проверяем, что шаблон принадлежит выбранному предприятию
        $check_stmt = $conn->prepare("SELECT TemplateID FROM sms_templates WHERE TemplateID = ? AND CompanyID = ?");
        $check_stmt->bind_param("ii", $templateId, $companyId);
        $check_stmt->execute();
        $exists = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        
        if (!$exists) {
            $conn->close();
            return ['success' => false, 'message' => 'Шаблон не найден или не принадлежит выбранному предприятию'];
        }
        
        $stmt = $conn->prepare("UPDATE sms_templates SET TemplateName = ?, TemplateText = ? WHERE TemplateID = ? AND CompanyID = ?");
        $stmt->bind_param("ssii", $templateName, $templateText, $templateId, $companyId);
        
        if ($stmt->execute()) {
            logSystemAction('sms_templates', 'update', 'Обновлен шаблон SMS ID=' . $templateId . ': ' . $templateName);
            $stmt->close();
            $conn->close();
            return ['success' => true, 'message' => 'Шаблон успешно обновлен'];
        } else {
            $stmt->close();
            $conn->close();
            return ['success' => false, 'message' => 'Ошибка при обновлении шаблона'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()];
    }
}

function deleteSmsTemplate($data) {
    try {
        $templateId = intval($data['id'] ?? 0);
        $companyId = getSelectedCompany();
        
        if ($templateId <= 0) {
            return ['success' => false, 'message' => 'Некорректный ID шаблона'];
        }
        
        if (!$companyId) {
            return ['success' => false, 'message' => 'Предприятие не выбрано'];
        }
        
        $conn = connectToDatabase();
        ensureSmsTemplatesTable($conn);
        
        // Проверяем, что шаблон принадлежит выбранному предприятию
        $check_stmt = $conn->prepare("SELECT TemplateName FROM sms_templates WHERE TemplateID = ? AND CompanyID = ?");
        $check_stmt->bind_param("ii", $templateId, $companyId);
        $check_stmt->execute();
        $template = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        
        if (!$template) {
            $conn->close();
            return ['success' => false, 'message' => 'Шаблон не найден или не принадлежит выбранному предприятию'];
        }
        
        $stmt = $conn->prepare("DELETE FROM sms_templates WHERE TemplateID = ? AND CompanyID = ?");
        $stmt->bind_param("ii", $templateId, $companyId);
        
        if ($stmt->execute()) {
            logSystemAction('sms_templates', 'delete', 'Удален шаблон SMS ID=' . $templateId . ': ' . ($template['TemplateName'] ?? ''));
            $stmt->close();
            $conn->close();
            return ['success' => true, 'message' => 'Шаблон успешно удален'];
        } else {
            $stmt->close();
            $conn->close();
            return ['success' => false, 'message' => 'Ошибка при удалении шаблона'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()];
    }
}

function fetchSmsTemplates() {
    try {
        $companyId = getSelectedCompany();
        if (!$companyId) {
            return [];
        }
        
        $conn = connectToDatabase();
        ensureSmsTemplatesTable($conn);
        
        $stmt = $conn->prepare("SELECT TemplateID, TemplateName, TemplateText, CreatedAt, UpdatedAt FROM sms_templates WHERE CompanyID = ? ORDER BY TemplateID DESC");
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $templates = [];
        while ($row = $result->fetch_assoc()) {
            $templates[] = $row;
        }
        
        $stmt->close();
        $conn->close();
        
        return $templates;
    } catch (Exception $e) {
        return [];
    }
}

function getSmsTemplateById($id) {
    try {
        $companyId = getSelectedCompany();
        if (!$companyId) {
            return null;
        }
        
        $conn = connectToDatabase();
        ensureSmsTemplatesTable($conn);
        
        $stmt = $conn->prepare("SELECT TemplateID, TemplateName, TemplateText, CreatedAt, UpdatedAt FROM sms_templates WHERE TemplateID = ? AND CompanyID = ?");
        $stmt->bind_param("ii", $id, $companyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $template = $result->fetch_assoc();
        
        $stmt->close();
        $conn->close();
        
        return $template;
    } catch (Exception $e) {
        return null;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Панель администратора - Система СМС информирования</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: url("fon.gif") center/cover fixed no-repeat, #eef2f7;
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
            max-width: 1400px;
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

        .container {
            max-width: 1400px;
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

        .char-counter {
            text-align: right;
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        .char-counter.warning {
            color: #ff9800;
        }

        .char-counter.error {
            color: #f44336;
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

        .tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
        }

        .tab {
            padding: 15px 25px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            color: #666;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
        }

        .tab.active {
            color: #4CAF50;
            border-bottom-color: #4CAF50;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        .data-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }

        .data-table tr:hover {
            background: #f8f9fa;
        }

        .delete-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
        }

        .delete-btn:hover {
            background: #c82333;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .stat-card {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
            padding: 20px;
            border-radius: 15px;
            text-align: center;
        }

        .stat-number {
            font-size: 2em;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }

        @media (max-width: 768px) {
            .main-content {
                grid-template-columns: 1fr;
            }
            
            .header-content {
                flex-direction: column;
                gap: 15px;
            }
        }

        /* Стили для пользовательских сообщений */
        .message-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin: 10px 0;
            border-left: 4px solid #4CAF50;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .message-item:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        .message-item.sent-message {
            border-left-color: #4CAF50;
            background: linear-gradient(135deg, #f8f9fa 0%, #e8f5e8 100%);
        }

        .message-item.received-message {
            border-left-color: #2196F3;
            background: linear-gradient(135deg, #f8f9fa 0%, #e3f2fd 100%);
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }

        .message-text {
            font-weight: 600;
            color: #333;
            flex: 1;
            margin-right: 15px;
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

        .status-toggle-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
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

        .status-indicator {
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
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

        .message-details {
            background: rgba(255, 255, 255, 0.7);
            padding: 10px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            font-size: 12px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }

        .detail-label {
            font-weight: 600;
            color: #555;
        }

        .detail-value {
            color: #333;
        }

        .group-badge {
            background: #e3f2fd;
            color: #1976d2;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 10px;
            margin-left: 5px;
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

        /* Стили для шаблонов в модальном окне */
        .template-item {
            background: #f8f9fa;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .template-item:hover {
            background: #e3f2fd;
            border-color: #4CAF50;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(76, 175, 80, 0.2);
        }

        .template-item:active {
            transform: translateY(0);
        }

        .template-name {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .template-name::before {
            content: "📝";
            font-size: 20px;
        }

        .template-text {
            font-size: 14px;
            color: #666;
            line-height: 1.6;
            word-wrap: break-word;
            padding: 10px;
            background: white;
            border-radius: 8px;
            border-left: 3px solid #4CAF50;
        }

        .template-empty {
            text-align: center;
            padding: 40px;
            color: #999;
            font-size: 16px;
        }
    </style>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="app-shell">
    <?php include 'navigation.php'; ?>
    <div class="container">
        <!-- Статистика -->
        <div class="card">
            <h2><i class="fas fa-chart-bar"></i> Статистика системы</h2>
            <div class="stats" id="statsContainer">
                <div class="stat-card">
                    <div class="stat-number" id="totalRecipients">0</div>
                    <div class="stat-label">Всего получателей</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="totalGroups">0</div>
                    <div class="stat-label">Всего групп</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="totalMessages">0</div>
                    <div class="stat-label">Отправлено сообщений</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="totalSent">0</div>
                    <div class="stat-label">Всего SMS</div>
                </div>
            </div>
        </div>

        <div class="main-content" id="mainContentContainer">

            <!-- Управление данными -->
            <div class="card">
                <div class="tabs">
                    
                    <button class="tab active" onclick="showTab('groups')">
                        <i class="fas fa-layer-group"></i> Группы
                    </button>
                    <button class="tab" onclick="showTab('messages')">
                        <i class="fas fa-envelope"></i> Сообщения
                    </button>
                    <button class="tab" onclick="showTab('systemlog')">
                        <i class="fas fa-list"></i> Журнал
                    </button>
                    <button class="tab" onclick="showTab('backup')">
                        <i class="fas fa-database"></i> Бекап БД
                    </button>
                    <button class="tab" onclick="showTab('import')">
                        <i class="fas fa-file-import"></i> Импорт данных
                    </button>
                    <button class="tab" onclick="showTab('users')">
                        <i class="fas fa-user-shield"></i> Пользователи
                    </button>
                    <button class="tab" onclick="showTab('user_messages')">
                        <i class="fas fa-comments"></i> Отправление СМС
                    </button>
                    <button class="tab" onclick="showTab('sms_templates')">
                        <i class="fas fa-file-alt"></i> Создание шаблона SMS
                    </button>
                </div>

                

                <!-- Вкладка групп -->
                <div class="tab-content active" id="groupsTab">
                    <h3>Добавить группу</h3>
                    <div class="form-group">
                        <input type="text" id="newGroupName" placeholder="Название группы">
                    </div>
                    <button class="btn" onclick="addGroup()">
                        <i class="fas fa-plus"></i> Добавить
                    </button>
                    
                    <h3 style="margin-top: 30px;">Список групп</h3>
                    <div id="groupsTable">
                        <div class="loading">Загрузка групп...</div>
                    </div>
                </div>

                <!-- Вкладка сообщений -->
                <div class="tab-content" id="messagesTab">
                    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;">
                        <h3>История сообщений</h3>
                        <div style="display:flex;gap:10px;align-items:center;">
                            <select id="messagesRange">
                                <option value="all">Все</option>
                                <option value="day">24 часа</option>
                                <option value="week">7 дней</option>
                                <option value="month">30 дней</option>
                                <option value="year">365 дней</option>
                            </select>
                            <button class="btn btn-danger" type="button" onclick="clearMessageHistory()"><i class="fas fa-trash"></i> Очистить</button>
                        </div>
                    </div>
                    <div id="messagesTable">
                        <div class="loading">Загрузка сообщений...</div>
                    </div>
                </div>

                <!-- Вкладка журнала системы -->
                <div class="tab-content" id="systemlogTab">
                    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px;">
                        <h3>Журнал действий системы</h3>
                        <div style="display:flex;gap:10px;align-items:center;">
                            <select id="logRangeSelect">
                                <option value="all">Все</option>
                                <option value="day">24 часа</option>
                                <option value="week">7 дней</option>
                                <option value="month">30 дней</option>
                                <option value="year">365 дней</option>
                            </select>
                            <button class="btn" type="button" onclick="loadSystemLogs()"><i class="fas fa-sync-alt"></i> Обновить журнал</button>
                            <button class="btn btn-danger" type="button" onclick="clearSystemLogs()"><i class="fas fa-trash"></i> Очистить</button>
                        </div>
                    </div>
                    <div id="systemLogTable">
                        <div class="loading">Загрузка журнала...</div>
                    </div>
                </div>

                <!-- Вкладка бекапа БД -->
                <div class="tab-content" id="backupTab">
                    <h3>Бекап базы данных</h3>
                    <div class="form-group">
                        <button class="btn" type="button" onclick="createBackup()">
                            <i class="fas fa-download"></i> Создать бекап
                        </button>
                    </div>
                    <div id="backupStatus" class="status-message" style="display:none"></div>
                    <div id="backupsTable">
                        <div class="loading">Загрузка списка бекапов...</div>
                    </div>
                </div>

                <!-- Вкладка импорта данных -->
                <div class="tab-content" id="importTab">
                    <h3>Импорт данных из JSON бекапа</h3>
                    <div class="form-group">
                        <input type="file" id="importFile" accept="application/json" style="display: none;">
                        <button type="button" class="btn" onclick="document.getElementById('importFile').click()">
                            <i class="fas fa-folder-open"></i> Выберите файл
                        </button>
                        <span id="selectedFileName" style="margin-left: 10px; color: #666; font-size: 14px;"></span>
                    </div>
                    <div class="form-group">
                        <label>Режим импорта</label>
                        <select id="importMode">
                            <option value="append">Добавить к существующим данным</option>
                            <option value="replace">Заменить выбранные таблицы</option>
                        </select>
                    </div>
                    <div class="form-group" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px">
                        <label><input type="checkbox" id="impGroups" checked> Группы</label>
                        <label><input type="checkbox" id="impRecipients" checked> Получатели</label>
                        <label><input type="checkbox" id="impMessages" checked> Сообщения</label>
                        <label><input type="checkbox" id="impLogs" checked> Логи отправок</label>
                        <label><input type="checkbox" id="impFeedback" checked> Обратная связь</label>
                        <label><input type="checkbox" id="impUsers"> Пользователи (небезопасно)</label>
                    </div>
                    <div class="form-group">
                        <button class="btn" type="button" onclick="runImport()">
                            <i class="fas fa-file-import"></i> Импортировать
                        </button>
                    </div>
                    <div id="importStatus" class="status-message" style="display:none"></div>
                </div>


                <!-- Вкладка пользователей -->
                <div class="tab-content" id="usersTab">
                    <h3>Добавить пользователя</h3>
                    <div class="form-group">
                        <input type="text" id="newUsername" placeholder="Имя пользователя">
                    </div>
                    <div class="form-group">
                        <input type="text" id="newUserPassword" placeholder="Пароль">
                    </div>
                    <div class="form-group">
                        <select id="newUserRole">
                            <option value="user">Пользователь</option>
                            <option value="admin">Администратор</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <input type="text" id="newUserPhone" placeholder="Номер телефона (опционально): +7XXXXXXXXXX или 8XXXXXXXXXX">
                        <small style="color: #666; font-size: 12px; margin-top: 5px; display: block;">
                            Номер будет автоматически нормализован. Формат: +7XXXXXXXXXX
                        </small>
                    </div>
                    <button class="btn" onclick="addUser()">
                        <i class="fas fa-user-plus"></i> Добавить пользователя
                    </button>

                    <h3 style="margin-top: 30px;">Список пользователей</h3>
                    <div id="usersTable">
                        <div class="loading">Загрузка пользователей...</div>
                    </div>
                </div>

                <!-- Вкладка пользовательских сообщений -->
                <div class="tab-content" id="user_messagesTab">
                    <!-- Отправка личного сообщения -->
                    <div style="margin-bottom: 30px;">
                        <h3>📱 Отправка личного сообщения</h3>
                        <div class="form-group">
                            <label for="adminRecipientSearch">Поиск получателя</label>
                            <input type="search" id="adminRecipientSearch" placeholder="Поиск по ФИО или номеру">
                        </div>
                        <div class="form-group">
                            <label for="adminFilterGroup">Фильтр по группе</label>
                            <select id="adminFilterGroup">
                                <option value="">Все группы</option>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?php echo htmlspecialchars($group['GroupName']); ?>">
                                        <?php echo htmlspecialchars($group['GroupName']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <div class="toolbar" style="margin-top: 8px;">
                                <div class="pill">📱 Выбрано: <span id="adminSelectedCount">0</span></div>
                                <div class="actions">
                                    <button type="button" class="btn btn-secondary" id="adminSelectAllBtn">Выбрать всех</button>
                                    <button type="button" class="btn btn-ghost" id="adminClearSelectionBtn">Очистить</button>
                                </div>
                            </div>
                            <div class="recipient-list" id="adminRecipientsList" style="max-height: 320px; overflow-y: auto; margin-top:10px;">
                                <div class="loading">Загрузка получателей...</div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Шаблоны сообщений:</label>
                            <button type="button" class="btn btn-secondary" onclick="openTemplatesModal()" style="width: 100%; margin-bottom: 5px;">
                                <i class="fas fa-file-alt"></i> Выбрать шаблон
                            </button>
                            <small style="color: #666; font-size: 12px; margin-top: 5px; display: block;">
                                При выборе шаблона его текст автоматически подставится в поле сообщения. Вы можете отредактировать текст перед отправкой.
                            </small>
                        </div>
                        <div class="form-group">
                            <label for="userMessageText">Текст сообщения:</label>
                            <textarea id="userMessageText" placeholder="Введите текст сообщения..." maxlength="160" disabled></textarea>
                            <div class="char-counter" id="userCharCounter">0/160 символов</div>
                        </div>
                        <button class="btn" onclick="sendUserMessage()">
                            <i class="fas fa-paper-plane"></i> Отправить СМС
                        </button>
                        <div class="status-message" id="statusMessage"></div>
                    </div>

                </div>

                <!-- Вкладка шаблонов SMS -->
                <div class="tab-content" id="sms_templatesTab">
                    <h3>Создание шаблона SMS</h3>
                    <div class="form-group">
                        <label for="templateName">Название шаблона:</label>
                        <input type="text" id="templateName" placeholder="Введите название шаблона">
                    </div>
                    <div class="form-group">
                        <label for="templateText">Текст шаблона:</label>
                        <textarea id="templateText" placeholder="Введите текст шаблона SMS..." rows="6"></textarea>
                        <div class="char-counter" id="templateCharCounter">0 символов</div>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn" onclick="saveSmsTemplate()" id="saveTemplateBtn">
                            <i class="fas fa-save"></i> Сохранить шаблон
                        </button>
                        <button class="btn btn-secondary" onclick="clearTemplateForm()" id="clearTemplateBtn" style="display: none;">
                            <i class="fas fa-times"></i> Отмена
                        </button>
                    </div>
                    <div class="status-message" id="templateStatusMessage"></div>
                    
                    <h3 style="margin-top: 40px;">Список шаблонов</h3>
                    <div id="smsTemplatesTable">
                        <div class="loading">Загрузка шаблонов...</div>
                    </div>
                </div>
            </div>
            
            <!-- Правая колонка для отправленных сообщений -->
            <div class="card" id="sentMessagesSidebar" style="display: none;">
                <h2><i class="fas fa-paper-plane"></i> Отправленные сообщения</h2>
                <div id="sentMessagesList" style="max-height: 600px; overflow-y: auto;">
                    <div class="loading">Загрузка отправленных сообщений...</div>
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
                        <span class="detail-label">📞 Номер телефона:</span>
                        <span id="modalPhone" class="detail-value"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal()">Закрыть</button>
            </div>
        </div>
    </div>

    <!-- Модальное окно для редактирования пользователя -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Редактировать пользователя</h3>
                <span class="close" onclick="closeEditUserModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="editUserForm">
                    <input type="hidden" id="editUserId">
                    <div class="form-group">
                        <label for="editUsername">Имя пользователя:</label>
                        <input type="text" id="editUsername" required>
                    </div>
                    <div class="form-group">
                        <label for="editPassword">Новый пароль (оставьте пустым, чтобы не изменять):</label>
                        <input type="password" id="editPassword" placeholder="Введите новый пароль">
                    </div>
                    <div class="form-group">
                        <label for="editRole">Роль:</label>
                        <select id="editRole">
                            <option value="user">Пользователь</option>
                            <option value="admin">Администратор</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="editStatus">Статус:</label>
                        <select id="editStatus">
                            <option value="active">Активный</option>
                            <option value="blocked">Заблокирован</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="editPhoneNumber">Номер телефона:</label>
                        <input type="text" id="editPhoneNumber" placeholder="+7XXXXXXXXXX или 8XXXXXXXXXX">
                        <small style="color: #666; font-size: 12px; margin-top: 5px; display: block;">
                            Номер будет автоматически нормализован. Формат: +7XXXXXXXXXX
                        </small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeEditUserModal()">Отмена</button>
                <button class="btn btn-danger" onclick="deleteUserFromModal()">Удалить</button>
                <button class="btn" onclick="saveUserChanges()">Сохранить изменения</button>
            </div>
        </div>
    </div>

    <!-- Модальное окно для выбора шаблонов сообщений -->
    <div id="templatesModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h3>Выберите шаблон сообщения</h3>
                <span class="close" onclick="closeTemplatesModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div id="templatesList" style="max-height: 500px; overflow-y: auto;">
                    <div class="loading">Загрузка шаблонов...</div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeTemplatesModal()">Закрыть</button>
            </div>
        </div>
    </div>

    <script>
        let groups = [];
        let messages = [];
        let users = [];
        let recipients = [];
        let systemLogsData = [];
        let messageHistoryData = [];
        let smsTemplates = [];
        let editingTemplateId = null;

        // Инициализация
        document.addEventListener('DOMContentLoaded', function() {
            loadGroups();
            loadMessages();
            loadStatistics();
            loadUsers();
            loadRecipients();
            loadSystemLogs();
            loadBackups();
            loadUserMessages();
            loadSmsTemplates();
            
            // Проверяем активную вкладку при загрузке и управляем видимостью блока сообщений
            const activeTab = document.querySelector('.tab.active');
            if (activeTab && activeTab.getAttribute('onclick') && activeTab.getAttribute('onclick').includes("'user_messages'")) {
                const sentMessagesSidebar = document.getElementById('sentMessagesSidebar');
                const mainContent = document.getElementById('mainContentContainer');
                if (sentMessagesSidebar) {
                    sentMessagesSidebar.style.display = 'block';
                }
                if (mainContent) {
                    mainContent.style.gridTemplateColumns = '1fr 1fr';
                }
            }

            // Счетчик символов для пользовательских сообщений
            const userMessageTextEl = document.getElementById('userMessageText');
            if (userMessageTextEl) {
                userMessageTextEl.addEventListener('input', function() {
                    const length = this.value.length;
                    const counter = document.getElementById('userCharCounter');
                    if (counter) {
                        counter.textContent = `${length}/160 символов`;
                        if (length > 140) {
                            counter.className = 'char-counter warning';
                        } else if (length > 160) {
                            counter.className = 'char-counter error';
                        } else {
                            counter.className = 'char-counter';
                        }
                    }
                });
            }

            // Счетчик символов для шаблонов SMS
            const templateTextEl = document.getElementById('templateText');
            if (templateTextEl) {
                templateTextEl.addEventListener('input', function() {
                    const length = this.value.length;
                    const counter = document.getElementById('templateCharCounter');
                    if (counter) {
                        counter.textContent = `${length} символов`;
                    }
                });
            }

            // Обработчики для модального окна сообщений
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
                if (event.target == document.getElementById('editUserModal')) {
                    closeEditUserModal();
                }
                if (event.target == document.getElementById('templatesModal')) {
                    closeTemplatesModal();
                }
            }

            // Закрытие по клавише Escape
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeModal();
                    closeEditUserModal();
                    closeTemplatesModal();
                }
            });

            // Обработчик выбора файла для импорта
            const importFileInput = document.getElementById('importFile');
            const selectedFileName = document.getElementById('selectedFileName');
            if (importFileInput && selectedFileName) {
                importFileInput.addEventListener('change', function() {
                    if (this.files && this.files.length > 0) {
                        selectedFileName.textContent = 'Выбран файл: ' + this.files[0].name;
                    } else {
                        selectedFileName.textContent = '';
                    }
                });
            }
        });


        // Секция таблицы получателей удалена

        // Загрузка групп
        function loadGroups() {
            fetch('admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_groups'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    groups = data.data;
                    displayGroupsTable();
                }
            })
            .catch(error => {
                console.error('Ошибка загрузки групп:', error);
            });
        }

        // Отображение таблицы групп
        function displayGroupsTable() {
            const container = document.getElementById('groupsTable');
            container.innerHTML = '';

            if (groups.length === 0) {
                container.innerHTML = '<p style="text-align: center; color: #666;">Нет групп</p>';
                return;
            }

            let table = '<table class="data-table"><thead><tr><th>Название</th><th>ID</th><th>Действия</th></tr></thead><tbody>';
            
            groups.forEach(group => {
                table += `
                    <tr>
                        <td>${group.GroupName}</td>
                        <td>${group.GroupID}</td>
                        <td><button class="delete-btn" onclick="deleteGroup(${group.GroupID})">Удалить</button></td>
                    </tr>
                `;
            });
            
            table += '</tbody></table>';
            container.innerHTML = table;
        }

        // Загрузка сообщений
        function loadMessages() {
            // История для таблицы
            fetch('admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_message_history'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    messageHistoryData = data.data || [];
                    applyMessagesFilter();
                }
            })
            .catch(error => {
                console.error('Ошибка загрузки истории сообщений:', error);
            });

            // Список текстов для шаблонов
            fetch('admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_messages'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const msgList = data.data;
                    updateTemplateSelectWithList(msgList);
                }
            })
            .catch(error => {
                console.error('Ошибка загрузки шаблонов сообщений:', error);
            });
        }

        // Загрузка журнала системы
        function loadSystemLogs() {
            fetch('admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_system_logs'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    systemLogsData = data.data || [];
                    applySystemLogFilter();
                }
            })
            .catch(error => {
                const container = document.getElementById('systemLogTable');
                if (container) container.innerHTML = '<p style="color:#c00">Ошибка загрузки журнала: ' + (error.message || error) + '</p>';
            });
        }

        function applySystemLogFilter() {
            const range = document.getElementById('logRangeSelect')?.value || 'all';
            const filtered = filterByRange(systemLogsData, 'CreatedAt', range);
            displaySystemLogTable(filtered);
        }

        function displaySystemLogTable(items) {
            const container = document.getElementById('systemLogTable');
            if (!container) return;
            container.innerHTML = '';
            if (!items || items.length === 0) {
                container.innerHTML = '<p style="text-align: center; color: #666;">Нет записей журнала</p>';
                return;
            }
            let table = '<table class="data-table"><thead><tr><th>Время</th><th>Категория</th><th>Действие</th><th>Пользователь</th><th>Подробности</th></tr></thead><tbody>';
            items.forEach(row => {
                table += `
                    <tr>
                        <td>${row.CreatedAt || ''}</td>
                        <td>${row.Category || ''}</td>
                        <td>${row.Action || ''}</td>
                        <td>${row.PerformedBy || ''}</td>
                        <td>${row.Details || ''}</td>
                    </tr>
                `;
            });
            table += '</tbody></table>';
            container.innerHTML = table;
        }

        // Бекапы
        function loadBackups() {
            fetch('admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=list_db_backups'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    renderBackupsTable(data.data || []);
                }
            })
            .catch(err => {
                const c = document.getElementById('backupsTable');
                if (c) c.innerHTML = '<p style="color:#c00">Ошибка загрузки бекапов: ' + (err.message || err) + '</p>';
            });
        }

        function createBackup() {
            const btn = event && event.target ? event.target : null;
            if (btn) { btn.disabled = true; btn.textContent = 'Создание...'; }
            fetch('admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=create_db_backup'
            })
            .then(r => r.json())
            .then(data => {
                const s = document.getElementById('backupStatus');
                if (s) {
                    s.textContent = data.message || '';
                    s.className = 'status-message ' + (data.success ? 'status-success' : 'status-error');
                    s.style.display = 'block';
                    setTimeout(() => s.style.display = 'none', 5000);
                }
                if (data.success) { loadBackups(); }
            })
            .catch(err => {
                const s = document.getElementById('backupStatus');
                if (s) {
                    s.textContent = 'Ошибка бекапа: ' + (err.message || err);
                    s.className = 'status-message status-error';
                    s.style.display = 'block';
                    setTimeout(() => s.style.display = 'none', 5000);
                }
            })
            .finally(() => { if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-download"></i> Создать бекап'; } });
        }

        function renderBackupsTable(items) {
            const container = document.getElementById('backupsTable');
            if (!container) return;
            container.innerHTML = '';
            if (!items || items.length === 0) {
                container.innerHTML = '<p style="text-align:center;color:#666">Бекапы отсутствуют</p>';
                return;
            }
            let table = '<table class="data-table"><thead><tr><th>Файл</th><th>Размер</th><th>Дата</th><th>Ссылка</th></tr></thead><tbody>';
            items.forEach(b => {
                const sizeKb = Math.round((b.size || 0) / 1024) + ' KB';
                table += `
                    <tr>
                        <td>${b.name}</td>
                        <td>${sizeKb}</td>
                        <td>${b.mtime || ''}</td>
                        <td><a class="btn" href="${b.url}" download>Скачать</a></td>
                    </tr>
                `;
            });
            table += '</tbody></table>';
            container.innerHTML = table;
        }

        // Импорт данных
        function runImport() {
            const fileInput = document.getElementById('importFile');
            if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                showImportStatus('Выберите JSON файл бекапа', 'error');
                return;
            }
            const form = new FormData();
            form.append('action', 'import_db_backup');
            form.append('file', fileInput.files[0]);
            form.append('mode', document.getElementById('importMode').value);
            form.append('import_groups', document.getElementById('impGroups').checked ? '1' : '0');
            form.append('import_recipients', document.getElementById('impRecipients').checked ? '1' : '0');
            form.append('import_messages', document.getElementById('impMessages').checked ? '1' : '0');
            form.append('import_logs', document.getElementById('impLogs').checked ? '1' : '0');
            form.append('import_feedback', document.getElementById('impFeedback').checked ? '1' : '0');
            form.append('import_users', document.getElementById('impUsers').checked ? '1' : '0');

            const btn = event && event.target ? event.target : null;
            if (btn) { btn.disabled = true; btn.textContent = 'Импорт...'; }

            fetch('admin.php', { method: 'POST', body: form })
            .then(r => r.json())
            .then(data => {
                showImportStatus(data.message || '', data.success ? 'success' : 'error');
                if (data.success) {
                    // Очищаем выбранный файл после успешного импорта
                    const fileInput = document.getElementById('importFile');
                    const fileNameSpan = document.getElementById('selectedFileName');
                    if (fileInput) fileInput.value = '';
                    if (fileNameSpan) fileNameSpan.textContent = '';
                    
                    loadRecipients();
                    loadGroups();
                    loadMessages();
                    loadStatistics();
                    loadSystemLogs();
                    loadBackups();
                }
            })
            .catch(err => showImportStatus('Ошибка импорта: ' + (err.message || err), 'error'))
            .finally(() => { if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-file-import"></i> Импортировать'; } });
        }

        function showImportStatus(message, type) {
            const st = document.getElementById('importStatus');
            if (!st) return;
            st.textContent = message;
            st.className = 'status-message status-' + type;
            st.style.display = 'block';
            setTimeout(() => st.style.display = 'none', 6000);
        }


        // Загрузка пользователей
        function loadUsers() {
            fetch('admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_users'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    users = data.data;
                    displayUsersTable();
                }
            })
            .catch(error => {
                console.error('Ошибка загрузки пользователей:', error);
            });
        }

        // Загрузка получателей
        function loadRecipients() {
            fetch('admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_recipients'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    recipients = data.data;
                    updateUserRecipientSelect();
                    renderAdminRecipientsList();
                }
            })
            .catch(error => {
                console.error('Ошибка загрузки получателей:', error);
            });
        }

        function displayUsersTable() {
            const container = document.getElementById('usersTable');
            container.innerHTML = '';

            if (users.length === 0) {
                container.innerHTML = '<p style="text-align: center; color: #666;">Нет пользователей</p>';
                return;
            }

            let table = '<table class="data-table"><thead><tr><th>Имя</th><th>Телефон</th><th>Роль</th><th>Статус</th><th>Создан</th><th>Действия</th></tr></thead><tbody>';
            users.forEach(u => {
                const phone = u.PhoneNumber || '—';
                table += `
                    <tr>
                        <td>${u.Username}</td>
                        <td>${phone}</td>
                        <td>${u.Role}</td>
                        <td>${u.Status}</td>
                        <td>${u.CreatedAt || ''}</td>
                        <td><button class="btn" onclick="editUser(${u.UserID}, '${u.Username}', '${u.Role}', '${u.Status}', '${(u.PhoneNumber || '').replace(/'/g, "\\'")}')" style="padding: 5px 10px; font-size: 12px;">Редактировать</button></td>
                    </tr>
                `;
            });
            table += '</tbody></table>';
            container.innerHTML = table;
        }

        function addUser() {
            const username = document.getElementById('newUsername').value.trim();
            const password = document.getElementById('newUserPassword').value.trim();
            const role = document.getElementById('newUserRole').value;
            const phoneNumber = document.getElementById('newUserPhone').value.trim();

            if (!username || !password) {
                showStatus('Введите имя пользователя и пароль', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'add_user');
            formData.append('username', username);
            formData.append('password', password);
            formData.append('role', role);
            formData.append('phoneNumber', phoneNumber);

            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showStatus(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    document.getElementById('newUsername').value = '';
                    document.getElementById('newUserPassword').value = '';
                    document.getElementById('newUserRole').value = 'user';
                    document.getElementById('newUserPhone').value = '';
                    loadUsers();
                }
            })
            .catch(error => {
                showStatus('Ошибка при добавлении пользователя: ' + error.message, 'error');
            });
        }

        // Функции для редактирования пользователей
        function editUser(id, username, role, status, phoneNumber = '') {
            document.getElementById('editUserId').value = id;
            document.getElementById('editUsername').value = username;
            document.getElementById('editRole').value = role;
            document.getElementById('editStatus').value = status;
            document.getElementById('editPhoneNumber').value = phoneNumber || '';
            document.getElementById('editPassword').value = '';
            
            document.getElementById('editUserModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeEditUserModal() {
            document.getElementById('editUserModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function saveUserChanges() {
            const id = document.getElementById('editUserId').value;
            const username = document.getElementById('editUsername').value.trim();
            const password = document.getElementById('editPassword').value;
            const role = document.getElementById('editRole').value;
            const status = document.getElementById('editStatus').value;
            const phoneNumber = document.getElementById('editPhoneNumber').value.trim();

            if (!username) {
                showStatus('Введите имя пользователя', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'update_user');
            formData.append('id', id);
            formData.append('username', username);
            formData.append('password', password);
            formData.append('role', role);
            formData.append('status', status);
            formData.append('phoneNumber', phoneNumber);

            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showStatus(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    closeEditUserModal();
                    loadUsers();
                }
            })
            .catch(error => {
                showStatus('Ошибка при обновлении пользователя: ' + error.message, 'error');
            });
        }

        function deleteUserFromModal() {
            const id = document.getElementById('editUserId').value;
            const username = document.getElementById('editUsername').value.trim();
            if (!id) {
                showStatus('Не найден ID пользователя', 'error');
                return;
            }
            if (!confirm(`Удалить пользователя "${username || 'без имени'}"?`)) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'delete_user');
            formData.append('id', id);

            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showStatus(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    closeEditUserModal();
                    loadUsers();
                }
            })
            .catch(error => {
                showStatus('Ошибка при удалении пользователя: ' + error.message, 'error');
            });
        }

        // Фильтрация истории сообщений
        function applyMessagesFilter() {
            const range = document.getElementById('messagesRange')?.value || 'all';
            messages = filterByRange(messageHistoryData, 'SentDate', range);
            displayMessagesTable();
        }

        // Отображение таблицы сообщений
        function displayMessagesTable() {
            const container = document.getElementById('messagesTable');
            container.innerHTML = '';

            if (messages.length === 0) {
                container.innerHTML = '<p style="text-align: center; color: #666;">Нет сообщений</p>';
                return;
            }

            let table = '<table class="data-table"><thead><tr><th>Текст</th><th>Получатель</th><th>Группа</th><th>Дата</th></tr></thead><tbody>';
            messages.forEach(item => {
                const groupName = item.GroupName || '—';
                table += `
                    <tr>
                        <td>${item.MessageText}</td>
                        <td>${item.RecipientName}</td>
                        <td>${groupName}</td>
                        <td>${item.SentDate}</td>
                    </tr>
                `;
            });
            
            table += '</tbody></table>';
            container.innerHTML = table;
        }

        // Загрузка статистики
        function loadStatistics() {
            fetch('admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_statistics'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const stats = data.data;
                    document.getElementById('totalRecipients').textContent = stats.totalRecipients || 0;
                    document.getElementById('totalGroups').textContent = stats.totalGroups || 0;
                    document.getElementById('totalMessages').textContent = stats.totalMessages || 0;
                    document.getElementById('totalSent').textContent = stats.totalSent || 0;
                }
            })
            .catch(error => {
                console.error('Ошибка загрузки статистики:', error);
            });
        }



        // Добавление группы
        function addGroup() {
            const name = document.getElementById('newGroupName').value.trim();

            if (!name) {
                showStatus('Введите название группы', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'add_group');
            formData.append('name', name);

            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showStatus(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    document.getElementById('newGroupName').value = '';
                    loadGroups();
                    loadStatistics();
                }
            })
            .catch(error => {
                showStatus('Ошибка при добавлении: ' + error.message, 'error');
            });
        }

        // Функция удаления получателя больше не используется

        // Удаление группы
        function deleteGroup(id) {
            if (!confirm('Вы уверены, что хотите удалить эту группу?')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'delete_group');
            formData.append('id', id);

            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showStatus(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    loadGroups();
                    loadStatistics();
                }
            })
            .catch(error => {
                showStatus('Ошибка при удалении: ' + error.message, 'error');
            });
        }


        // Переключение вкладок
        function showTab(tabName) {
            // Скрываем все вкладки
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Показываем нужную вкладку
            document.getElementById(tabName + 'Tab').classList.add('active');
            event.target.classList.add('active');
            
            // Загружаем данные при переключении на вкладку шаблонов
            if (tabName === 'sms_templates') {
                loadSmsTemplates();
            }
            if (tabName === 'user_messages') {
                loadUserMessageTemplates();
            }
            
            // Управление видимостью блока "Отправленные сообщения"
            const sentMessagesSidebar = document.getElementById('sentMessagesSidebar');
            const mainContent = document.getElementById('mainContentContainer');
            
            if (tabName === 'user_messages') {
                // Показываем блок справа
                if (sentMessagesSidebar) {
                    sentMessagesSidebar.style.display = 'block';
                }
                // Изменяем grid на две колонки
                if (mainContent) {
                    mainContent.style.gridTemplateColumns = '1fr 1fr';
                }
            } else {
                // Скрываем блок
                if (sentMessagesSidebar) {
                    sentMessagesSidebar.style.display = 'none';
                }
                // Возвращаем одну колонку
                if (mainContent) {
                    mainContent.style.gridTemplateColumns = '1fr';
                }
            }
        }

        // Показ статуса
        function showStatus(message, type) {
            const statusDiv = document.getElementById('statusMessage');
            statusDiv.textContent = message;
            statusDiv.className = `status-message status-${type}`;
            statusDiv.style.display = 'block';

            setTimeout(() => {
                statusDiv.style.display = 'none';
            }, 5000);
        }


        // Функции для работы с пользовательскими сообщениями

        // Загрузка шаблонов (теперь используется только для хранения данных)
        let userMessageTemplates = [];
        function loadUserMessageTemplates() {
            const formData = new FormData();
            formData.append('action', 'get_sms_templates');

            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data) {
                    userMessageTemplates = data.data;
                }
            })
            .catch(error => {
                console.error('Ошибка загрузки шаблонов сообщений:', error);
            });
        }

        // Открытие модального окна с шаблонами
        function openTemplatesModal() {
            const modal = document.getElementById('templatesModal');
            const templatesList = document.getElementById('templatesList');
            
            if (!modal || !templatesList) return;

            // Загружаем шаблоны, если они еще не загружены
            if (userMessageTemplates.length === 0) {
                templatesList.innerHTML = '<div class="loading">Загрузка шаблонов...</div>';
                const formData = new FormData();
                formData.append('action', 'get_sms_templates');

                fetch('admin.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data) {
                        userMessageTemplates = data.data;
                        displayTemplatesInModal();
                    } else {
                        templatesList.innerHTML = '<div class="template-empty">Шаблоны не найдены</div>';
                    }
                })
                .catch(error => {
                    console.error('Ошибка загрузки шаблонов:', error);
                    templatesList.innerHTML = '<div class="template-empty">Ошибка загрузки шаблонов</div>';
                });
            } else {
                displayTemplatesInModal();
            }

            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        // Отображение шаблонов в модальном окне
        function displayTemplatesInModal() {
            const templatesList = document.getElementById('templatesList');
            if (!templatesList) return;

            if (userMessageTemplates.length === 0) {
                templatesList.innerHTML = '<div class="template-empty">Шаблоны не найдены</div>';
                return;
            }

            templatesList.innerHTML = '';
            userMessageTemplates.forEach(template => {
                const templateItem = document.createElement('div');
                templateItem.className = 'template-item';
                templateItem.onclick = () => selectTemplate(template.TemplateText);
                
                templateItem.innerHTML = `
                    <div class="template-name">${escapeHtml(template.TemplateName)}</div>
                    <div class="template-text">${escapeHtml(template.TemplateText)}</div>
                `;
                
                templatesList.appendChild(templateItem);
            });
        }

        // Выбор шаблона и добавление текста в поле сообщения
        function selectTemplate(templateText) {
            if (!templateText) return;

            const textarea = document.getElementById('userMessageText');
            if (!textarea) return;

            let current = textarea.value || '';
            if (current.trim().length === 0) {
                current = templateText;
            } else {
                current = current + ' ' + templateText;
            }
            textarea.value = current;

            // Триггерим обновление счетчика символов
            const event = new Event('input');
            textarea.dispatchEvent(event);

            // Закрываем модальное окно
            closeTemplatesModal();
        }

        // Закрытие модального окна с шаблонами
        function closeTemplatesModal() {
            const modal = document.getElementById('templatesModal');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }

        // Функция для экранирования HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        function syncUsers() {
            fetch('admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=sync_users'
            })
            .then(response => response.json())
            .then(data => {
                showStatus(data.message || 'Синхронизация завершена', data.success ? 'success' : 'error');
                if (data.success) {
                    loadRecipients();
                    loadUserMessages();
                }
            })
            .catch(error => {
                showStatus('Ошибка синхронизации: ' + error.message, 'error');
            });
        }

        function sendUserMessage() {
            const messageText = document.getElementById('userMessageText').value.trim();
            const selected = document.querySelectorAll('#adminRecipientsList input[type="checkbox"]:checked');

            if (!messageText) {
                showStatus('Введите текст сообщения', 'error');
                return;
            }

            const textarea = document.getElementById('userMessageText');
            if (!textarea) {
                showStatus('Поле ввода сообщения недоступно', 'error');
                return;
            }

            if (selected.length === 0) {
                showStatus('Выберите получателей. Если список пуст, сначала синхронизируйте пользователей.', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'send_user_message');
            formData.append('message_text', messageText);
            selected.forEach(cb => formData.append('recipients[]', cb.value));

            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showStatus(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    document.getElementById('userMessageText').value = '';
                    document.querySelectorAll('#adminRecipientsList input[type="checkbox"]').forEach(cb => cb.checked = false);
                    adminUpdateSelectedCount();
                    loadUserMessages();
                    loadMessages();
                    loadStatistics();
                }
            })
            .catch(error => {
                showStatus('Ошибка при отправке: ' + error.message, 'error');
            });
        }

        function loadUserMessages() {
            fetch('admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_user_messages'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayUserMessages(data.data);
                    renderAdminRecipientsList();
                    // Обновляем список шаблонов, если мы на вкладке пользовательских сообщений
                    const userMessagesTab = document.getElementById('user_messagesTab');
                    if (userMessagesTab && userMessagesTab.classList.contains('active')) {
                        loadUserMessageTemplates();
                    }
                }
            })
            .catch(error => {
                console.error('Ошибка загрузки пользовательских сообщений:', error);
            });
        }

        // --- Список получателей (админ вкладка) ---
        function renderAdminRecipientsList() {
            const list = document.getElementById('adminRecipientsList');
            if (!list) return;
            if (!recipients || recipients.length === 0) {
                list.innerHTML = '<p style="color:#6b7280;">Получатели отсутствуют. Синхронизируйте пользователей.</p>';
                adminUpdateSelectedCount();
                return;
            }

            const searchVal = (document.getElementById('adminRecipientSearch').value || '').toLowerCase().trim();
            const groupVal = (document.getElementById('adminFilterGroup').value || '').toLowerCase().trim();

            const filtered = recipients.filter(r => {
                const name = (r.FullName || '').toLowerCase();
                const phone = (r.PhoneNumber || '').toLowerCase();
                const group = (r.GroupName || '').toLowerCase();
                const matchText = !searchVal || name.includes(searchVal) || phone.includes(searchVal);
                const matchGroup = !groupVal || group === groupVal;
                return matchText && matchGroup;
            });

            if (filtered.length === 0) {
                list.innerHTML = '<p style="color:#6b7280;">Нет совпадений по фильтру.</p>';
                adminUpdateSelectedCount();
                return;
            }

            list.innerHTML = filtered.map(r => `
                <label class="recipient-card">
                    <input type="checkbox" value="${r.RecipientID}">
                    <div class="recipient-meta">
                        <div class="recipient-name">${r.FullName || ''}</div>
                        <div class="recipient-phone">${r.PhoneNumber || ''}</div>
                    </div>
                    ${r.GroupName ? `<span class="badge badge-muted">${r.GroupName}</span>` : ''}
                </label>
            `).join('');
            adminUpdateSelectedCount();
        }

        function adminUpdateSelectedCount() {
            const el = document.getElementById('adminSelectedCount');
            if (!el) return;
            const count = document.querySelectorAll('#adminRecipientsList input[type="checkbox"]:checked').length;
            el.textContent = count;
            const textarea = document.getElementById('userMessageText');
            if (textarea) textarea.disabled = count === 0;
        }

        function adminSelectAllVisible() {
            document.querySelectorAll('#adminRecipientsList .recipient-card').forEach(card => {
                if (card.style.display === 'none') return;
                const cb = card.querySelector('input[type="checkbox"]');
                if (cb) cb.checked = true;
            });
            adminUpdateSelectedCount();
        }

        function adminClearSelection() {
            document.querySelectorAll('#adminRecipientsList input[type="checkbox"]').forEach(cb => cb.checked = false);
            adminUpdateSelectedCount();
        }

        document.getElementById('adminRecipientSearch')?.addEventListener('input', renderAdminRecipientsList);
        document.getElementById('adminFilterGroup')?.addEventListener('change', renderAdminRecipientsList);
        document.getElementById('adminSelectAllBtn')?.addEventListener('click', adminSelectAllVisible);
        document.getElementById('adminClearSelectionBtn')?.addEventListener('click', adminClearSelection);
        document.getElementById('adminRecipientsList')?.addEventListener('change', adminUpdateSelectedCount);
        document.getElementById('messagesRange')?.addEventListener('change', applyMessagesFilter);
        document.getElementById('logRangeSelect')?.addEventListener('change', applySystemLogFilter);

        function displayUserMessages(data) {
    // Отображение отправленных сообщений
    const sentContainer = document.getElementById('sentMessagesList');
    if (data.sent && data.sent.length > 0) {
        sentContainer.innerHTML = data.sent.map(msg => `
            <div class="message-item sent-message" onclick="openMessageModal(${JSON.stringify(msg).replace(/"/g, '&quot;')})">
                <div class="message-header">
                    <div class="message-text">
                        <div class="message-type-badge">📤 Отправлено</div>
                        ${msg.Text}
                    </div>
                </div>
                <div class="message-details">
                    <div class="detail-row">
                        <span class="detail-label">📅 Дата:</span>
                        <span class="detail-value">${new Date(msg.SentDate).toLocaleString('ru-RU')}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">👤 Получатель:</span>
                        <span class="detail-value">${msg.ContactName} 
                            ${msg.GroupName ? `<span class="group-badge">${msg.GroupName}</span>` : ''}
                        </span>
                    </div>
                </div>
            </div>
        `).join('');
    } else {
        sentContainer.innerHTML = '<p style="text-align: center; color: #666;">Нет отправленных сообщений</p>';
    }
}

        function updateUserRecipientSelect() {
            const select = document.getElementById('userRecipientSelect');
            if (!select) return;
            
            select.innerHTML = '<option value="">-- Выберите получателя --</option>';
            
            if (!recipients || recipients.length === 0) {
                const option = document.createElement('option');
                option.value = '';
                option.textContent = 'Нет получателей. Сначала синхронизируйте пользователей.';
                option.disabled = true;
                select.appendChild(option);
                return;
            }
            
            recipients.forEach(recipient => {
                const option = document.createElement('option');
                option.value = recipient.RecipientID;
                option.textContent = `${recipient.FullName} ${recipient.GroupName ? `- ${recipient.GroupName}` : ''}`;
                select.appendChild(option);
            });
        }

        function updateReadStatus(userMessageId, newStatus) {
            const formData = new FormData();
            formData.append('action', 'update_read_status');
            formData.append('user_message_id', userMessageId);
            formData.append('read_status', newStatus);

            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showStatus(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    loadUserMessages();
                }
            })
            .catch(error => {
                showStatus('Ошибка обновления статуса: ' + error.message, 'error');
            });
        }

        function openMessageModal(messageData) {
            const modal = document.getElementById('messageModal');
            
            // Заполняем данные в модальном окне
            document.getElementById('modalMessageType').textContent = 
                messageData.MessageType === 'sent' ? '📤 Отправлено' : '📥 Получено';
            
            document.getElementById('modalMessageDate').textContent = 
                new Date(messageData.SentDate).toLocaleString('ru-RU');
            
            document.getElementById('modalMessageText').textContent = messageData.Text;
            
            // Определяем контакт в зависимости от типа сообщения
            const contactName = messageData.MessageType === 'sent' ? 
                messageData.ContactName : messageData.SenderName;
            document.getElementById('modalContact').textContent = contactName;
            document.getElementById('modalPhone').textContent = messageData.PhoneNumber || '—';
            
            // Автоматически отмечаем полученное сообщение как прочитанное при открытии
            if (messageData.MessageType === 'received' && messageData.ReadStatus === 'unread') {
                markAsRead(messageData.UserMessageID);
            }
            
            // Показываем модальное окно
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        // Функция для автоматической отметки сообщения как прочитанного
        function markAsRead(userMessageId) {
            const formData = new FormData();
            formData.append('action', 'update_read_status');
            formData.append('user_message_id', userMessageId);
            formData.append('read_status', 'read');

            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                // Обновляем страницу для отображения изменений
                setTimeout(() => {
                    loadUserMessages();
                }, 1000);
            })
            .catch(error => {
                console.error('Ошибка при отметке сообщения как прочитанного:', error);
            });
        }

        // Универсальный фильтр по временным диапазонам
        function filterByRange(items, fieldName, range) {
            if (!Array.isArray(items) || range === 'all') return items || [];
            const now = new Date();
            let cutoff = null;
            switch (range) {
                case 'day':   cutoff = new Date(now.getTime() - 24 * 60 * 60 * 1000); break;
                case 'week':  cutoff = new Date(now.getTime() - 7  * 24 * 60 * 60 * 1000); break;
                case 'month': cutoff = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000); break;
                case 'year':  cutoff = new Date(now.getTime() - 365 * 24 * 60 * 60 * 1000); break;
                default: return items;
            }
            return items.filter(item => {
                const dt = new Date(item[fieldName]);
                return !isNaN(dt) && dt >= cutoff;
            });
        }

        // Очистка журнала системы
        function clearSystemLogs() {
            if (!confirm('Очистить журнал действий системы?')) return;
            fetch('admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=clear_system_logs'
            })
            .then(r => r.json())
            .then(data => {
                showStatus(data.message || 'Журнал очищен', data.success ? 'success' : 'error');
                if (data.success) {
                    systemLogsData = [];
                    displaySystemLogTable([]);
                }
            })
            .catch(err => showStatus('Ошибка очистки журнала: ' + (err.message || err), 'error'));
        }

        // Очистка истории сообщений
        function clearMessageHistory() {
            if (!confirm('Очистить историю сообщений?')) return;
            fetch('admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=clear_message_history'
            })
            .then(r => r.json())
            .then(data => {
                showStatus(data.message || 'История очищена', data.success ? 'success' : 'error');
                if (data.success) {
                    messageHistoryData = [];
                    displayMessagesTable();
                    loadStatistics();
                }
            })
            .catch(err => showStatus('Ошибка очистки истории: ' + (err.message || err), 'error'));
        }

        function closeModal() {
            const modal = document.getElementById('messageModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Функции для работы с шаблонами SMS
        function loadSmsTemplates() {
            fetch('admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_sms_templates'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    smsTemplates = data.data || [];
                    displaySmsTemplatesTable();
                }
            })
            .catch(error => {
                console.error('Ошибка загрузки шаблонов:', error);
            });
        }

        function displaySmsTemplatesTable() {
            const container = document.getElementById('smsTemplatesTable');
            if (!container) return;
            
            container.innerHTML = '';
            
            if (smsTemplates.length === 0) {
                container.innerHTML = '<p style="text-align: center; color: #666;">Нет шаблонов</p>';
                return;
            }
            
            let table = '<table class="data-table"><thead><tr><th>Название</th><th>Текст</th><th>Создан</th><th>Обновлен</th><th>Действия</th></tr></thead><tbody>';
            
            smsTemplates.forEach(template => {
                const textPreview = template.TemplateText.length > 50 ? 
                    template.TemplateText.substring(0, 50) + '...' : 
                    template.TemplateText;
                const createdAt = template.CreatedAt ? new Date(template.CreatedAt).toLocaleString('ru-RU') : '—';
                const updatedAt = template.UpdatedAt ? new Date(template.UpdatedAt).toLocaleString('ru-RU') : '—';
                
                table += `
                    <tr>
                        <td><strong>${escapeHtml(template.TemplateName)}</strong></td>
                        <td>${escapeHtml(textPreview)}</td>
                        <td>${createdAt}</td>
                        <td>${updatedAt}</td>
                        <td>
                            <button class="btn" onclick="editSmsTemplate(${template.TemplateID})" style="padding: 5px 10px; font-size: 12px; margin-right: 5px;">
                                <i class="fas fa-edit"></i> Редактировать
                            </button>
                            <button class="btn btn-danger" onclick="deleteSmsTemplate(${template.TemplateID})" style="padding: 5px 10px; font-size: 12px;">
                                <i class="fas fa-trash"></i> Удалить
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            table += '</tbody></table>';
            container.innerHTML = table;
        }

        function saveSmsTemplate() {
            const templateName = document.getElementById('templateName').value.trim();
            const templateText = document.getElementById('templateText').value.trim();
            
            if (!templateName) {
                showTemplateStatus('Введите название шаблона', 'error');
                return;
            }
            
            if (!templateText) {
                showTemplateStatus('Введите текст шаблона', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', editingTemplateId ? 'update_sms_template' : 'add_sms_template');
            if (editingTemplateId) {
                formData.append('template_id', editingTemplateId);
            }
            formData.append('template_name', templateName);
            formData.append('template_text', templateText);
            
            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showTemplateStatus(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    clearTemplateForm();
                    loadSmsTemplates();
                }
            })
            .catch(error => {
                showTemplateStatus('Ошибка при сохранении шаблона: ' + error.message, 'error');
            });
        }

        function editSmsTemplate(id) {
            fetch('admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_sms_template&id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data) {
                    const template = data.data;
                    document.getElementById('templateName').value = template.TemplateName;
                    document.getElementById('templateText').value = template.TemplateText;
                    editingTemplateId = template.TemplateID;
                    
                    // Обновляем кнопки
                    document.getElementById('saveTemplateBtn').innerHTML = '<i class="fas fa-save"></i> Обновить шаблон';
                    document.getElementById('clearTemplateBtn').style.display = 'inline-block';
                    
                    // Прокручиваем к форме
                    document.getElementById('templateName').scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else {
                    showTemplateStatus('Шаблон не найден', 'error');
                }
            })
            .catch(error => {
                showTemplateStatus('Ошибка при загрузке шаблона: ' + error.message, 'error');
            });
        }

        function deleteSmsTemplate(id) {
            const template = smsTemplates.find(t => t.TemplateID === id);
            const templateName = template ? template.TemplateName : 'шаблон';
            
            if (!confirm(`Вы уверены, что хотите удалить шаблон "${templateName}"?`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete_sms_template');
            formData.append('id', id);
            
            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showTemplateStatus(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    loadSmsTemplates();
                }
            })
            .catch(error => {
                showTemplateStatus('Ошибка при удалении шаблона: ' + error.message, 'error');
            });
        }

        function clearTemplateForm() {
            document.getElementById('templateName').value = '';
            document.getElementById('templateText').value = '';
            editingTemplateId = null;
            document.getElementById('saveTemplateBtn').innerHTML = '<i class="fas fa-save"></i> Сохранить шаблон';
            document.getElementById('clearTemplateBtn').style.display = 'none';
            document.getElementById('templateCharCounter').textContent = '0 символов';
        }

        function showTemplateStatus(message, type) {
            const statusDiv = document.getElementById('templateStatusMessage');
            statusDiv.textContent = message;
            statusDiv.className = `status-message status-${type}`;
            statusDiv.style.display = 'block';
            
            setTimeout(() => {
                statusDiv.style.display = 'none';
            }, 5000);
        }
    </script>
</body>
</html>
