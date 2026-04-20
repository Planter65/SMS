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
            try {
                $username = trim($_POST['username'] ?? '');
                $password = $_POST['password'] ?? '';
                $role = $_POST['role'] ?? 'user';
                $phoneNumber = trim($_POST['phoneNumber'] ?? '');
                $groupID = isset($_POST['group_id']) ? $_POST['group_id'] : null;
                if ($username === '') {
                    echo json_encode(['success' => false, 'message' => 'Введите имя пользователя']);
                    exit;
                }
                if ($role !== 'recipient' && $password === '') {
                    echo json_encode(['success' => false, 'message' => 'Для ролей Пользователь и Администратор требуется пароль']);
                    exit;
                }
                $res = registerUser($username, $password ?: 'x', $role, $phoneNumber);
                if (!is_array($res)) {
                    $res = ['success' => false, 'message' => 'Ошибка при создании пользователя'];
                }
                if (!empty($res['success']) && isset($res['user_id'])) {
                    $phoneInfo = !empty($phoneNumber) ? ', телефон: ' . $phoneNumber : '';
                    logSystemAction('users', 'add', 'Добавлен пользователь: ' . $username . ', роль: ' . $role . $phoneInfo);
                    $companyIds = isset($_POST['company_ids']) ? $_POST['company_ids'] : [];
                    if (!is_array($companyIds)) {
                        $companyIds = $companyIds !== '' ? [(int)$companyIds] : [];
                    }
                    $companyIds = array_values(array_filter(array_map('intval', $companyIds), function ($id) { return $id > 0; }));
                    if (!empty($companyIds)) {
                        setUserCompanies((int)$res['user_id'], $companyIds);
                    }

                    // Если группа передана при создании — сохраняем её в users
                    if ($groupID !== null && $groupID !== '') {
                        $conn = connectToDatabase();
                        $gidVal = intval($groupID);
                        $uidVal = (int)$res['user_id'];
                        $stmt = $conn->prepare("UPDATE users SET GroupID = NULLIF(?, -1) WHERE UserID = ?");
                        $stmt->bind_param("ii", $gidVal, $uidVal);
                        @$stmt->execute();
                        $stmt->close();
                        $conn->close();
                    }

                    // Автодобавление/обновление в recipients для нового пользователя
                    $conn = connectToDatabase();
                    $uidVal = (int)$res['user_id'];
                    $stmt = $conn->prepare("SELECT Username, PhoneNumber, GroupID FROM users WHERE UserID = ? LIMIT 1");
                    $stmt->bind_param("i", $uidVal);
                    $stmt->execute();
                    $urow = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($urow) {
                        $un = trim((string)($urow['Username'] ?? ''));
                        $ph = trim((string)($urow['PhoneNumber'] ?? ''));
                        $gid = isset($urow['GroupID']) && $urow['GroupID'] !== '' && $urow['GroupID'] !== null ? (int)$urow['GroupID'] : null;
                        upsertRecipientForUser($conn, $un, $un, $ph, $gid);
                    }
                    $conn->close();
                } else {
                    logSystemAction('users', 'add_error', 'Ошибка добавления пользователя: ' . $username . ' — ' . ($res['message'] ?? ''));
                }
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($res);
            } catch (Exception $e) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
            }
            exit;
        case 'get_users':
            echo json_encode(['success' => true, 'data' => fetchUsersWithCompanies(), 'companies' => getCompanies()]);
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
            
            $groupID = isset($_POST['group_id']) ? $_POST['group_id'] : null;
            $updateResult = updateUserById($id, $username, $password, $role, $status, $phoneNumber, $groupID);
            if (is_array($updateResult) && !empty($updateResult['success'])) {
                $companyIds = isset($_POST['company_ids']) ? (is_array($_POST['company_ids']) ? $_POST['company_ids'] : []) : [];
                $companyIds = array_map('intval', $companyIds);
                $companyIds = array_filter($companyIds, function ($id) { return $id > 0; });
                setUserCompanies($id, array_values($companyIds));
                logSystemAction('users', 'update_companies', 'Обновлена привязка предприятий для пользователя ID=' . $id);
            }
            echo json_encode($updateResult);
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
            $filter = $_POST['filter'] ?? 'all';
            echo json_encode(['success' => true, 'data' => fetchUserMessages($filter)]);
            exit;
        case 'update_read_status':
            echo json_encode(updateMessageReadStatus($_POST));
            exit;
        case 'clear_system_logs':
            echo json_encode(clearSystemLogs());
            exit;
        case 'delete_user_message':
            echo json_encode(deleteUserMessage($_POST));
            exit;
        case 'clear_personal_messages':
            echo json_encode(clearPersonalMessagesForCurrentUser());
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
            echo json_encode(['success' => true, 'data' => fetchSmsTemplatesAll(), 'companies' => getCompanies()]);
            exit;
        case 'get_sms_template':
            $id = intval($_POST['id'] ?? 0);
            $companyId = isset($_POST['company_id']) ? intval($_POST['company_id']) : null;
            echo json_encode(['success' => true, 'data' => getSmsTemplateById($id, $companyId)]);
            exit;
        case 'get_checkbox_templates':
            $companyFilter = isset($_POST['company_filter']) ? intval($_POST['company_filter']) : null;
            echo json_encode(['success' => true, 'data' => fetchCheckboxTemplatesAll($companyFilter), 'companies' => getCompanies()]);
            exit;
        case 'get_checkbox_template':
            $id = intval($_POST['id'] ?? 0);
            $companyId = isset($_POST['company_id']) ? intval($_POST['company_id']) : null;
            echo json_encode(['success' => true, 'data' => getCheckboxTemplateById($id, $companyId)]);
            exit;
        case 'save_checkbox_template':
            echo json_encode(saveCheckboxTemplate($_POST));
            exit;
        case 'delete_checkbox_template':
            echo json_encode(deleteCheckboxTemplate($_POST));
            exit;
        case 'get_companies':
            echo json_encode(['success' => true, 'data' => getCompanies()]);
            exit;
        case 'add_company':
            $companyName = trim($_POST['company_name'] ?? '');
            $res = addCompany($companyName);
            if (is_array($res) && !empty($res['success'])) {
                logSystemAction('companies', 'add', 'Добавлено предприятие: ' . $companyName);
            }
            echo json_encode($res);
            exit;
        case 'update_company':
            $companyId = (int)($_POST['company_id'] ?? 0);
            $companyName = trim($_POST['company_name'] ?? '');
            $res = updateCompany($companyId, $companyName);
            if (is_array($res) && !empty($res['success'])) {
                logSystemAction('companies', 'update', 'Обновлено предприятие ID=' . $companyId . ': ' . $companyName);
            }
            echo json_encode($res);
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
               COALESCE(u.Username, 'Администратор') AS SenderName,
               r.FullName AS RecipientName,
               g.GroupName AS GroupName,
               ml.SentDate
        FROM messagelogs ml
        JOIN messages m   ON m.MessageID = ml.MessageID
        JOIN recipients r ON r.RecipientID = ml.RecipientID
        LEFT JOIN groups g ON g.GroupID = r.GroupID
        LEFT JOIN user_messages um ON um.MessageID = ml.MessageID AND um.RecipientID = ml.RecipientID
        LEFT JOIN users u ON u.UserID = um.SenderID
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
        // Порядок: сначала user_messages (ссылается на messages), затем messagelogs, затем messages
        $conn->query("DELETE FROM user_messages");
        $conn->query("DELETE FROM messagelogs");
        $conn->query("DELETE FROM messages");
        $conn->close();
        logSystemAction('messages', 'history_clear', 'История сообщений и личные сообщения очищены');
        return ['success' => true, 'message' => 'История сообщений очищена'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Ошибка очистки истории: ' . $e->getMessage()];
    }
}

// Очистка личных сообщений только для текущего администратора / пользователя
function clearPersonalMessagesForCurrentUser() {
    try {
        $conn = connectToDatabase();
        $user = getCurrentUser();
        $currentUserId = intval($user['id'] ?? 0);
        $currentUsername = $user['username'] ?? '';
        if ($currentUserId <= 0 || $currentUsername === '') {
            return ['success' => false, 'message' => 'Не удалось определить текущего пользователя'];
        }

        // Находим RecipientID текущего пользователя (если он есть в recipients)
        $recipientId = null;
        $stmt = $conn->prepare("SELECT RecipientID FROM recipients WHERE FullName = ? LIMIT 1");
        $stmt->bind_param("s", $currentUsername);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($res && isset($res['RecipientID'])) {
            $recipientId = (int)$res['RecipientID'];
        }

        // Удаляем только те записи из user_messages, где текущий пользователь — отправитель или получатель
        if ($recipientId !== null) {
            $stmt = $conn->prepare("DELETE FROM user_messages WHERE SenderID = ? OR RecipientID = ?");
            $stmt->bind_param("ii", $currentUserId, $recipientId);
        } else {
            $stmt = $conn->prepare("DELETE FROM user_messages WHERE SenderID = ?");
            $stmt->bind_param("i", $currentUserId);
        }
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();
        $conn->close();

        logSystemAction('messages', 'personal_history_clear', 'Очищены личные сообщения пользователя ID=' . $currentUserId);
        return ['success' => true, 'message' => 'Личные сообщения очищены (удалено записей: ' . max(0, $deleted) . ')'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Ошибка очистки личных сообщений: ' . $e->getMessage()];
    }
}

function deleteUserMessage($data) {
    $id = isset($data['user_message_id']) ? intval($data['user_message_id']) : 0;
    if ($id <= 0) {
        return ['success' => false, 'message' => 'Некорректный ID сообщения'];
    }
    try {
        $conn = connectToDatabase();
        $stmt = $conn->prepare("DELETE FROM user_messages WHERE UserMessageID = ?");
        $stmt->bind_param("i", $id);
        $ok = $stmt->execute();
        $stmt->close();
        $conn->close();
        if ($ok) {
            logSystemAction('messages', 'delete_personal', 'Удалено личное сообщение ID=' . $id);
        }
        return ['success' => (bool)$ok, 'message' => $ok ? 'Сообщение удалено' : 'Не удалось удалить'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()];
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
    $sql = "SELECT u.UserID, u.Username, u.PhoneNumber, u.Role, u.Status, u.CreatedAt, u.GroupID, g.GroupName 
            FROM users u 
            LEFT JOIN groups g ON u.GroupID = g.GroupID 
            ORDER BY u.UserID DESC";
    $result = $conn->query($sql);
    $rows = [];
    while ($row = $result->fetch_assoc()) { $rows[] = $row; }
    $conn->close();
    return $rows;
}

function fetchUsersWithCompanies() {
    $users = fetchUsers();
    foreach ($users as &$u) {
        $companies = getCompaniesForUser($u['UserID']);
        $u['CompanyIDs'] = array_column($companies, 'CompanyID');
        $u['CompanyNames'] = implode(', ', array_column($companies, 'CompanyName'));
    }
    unset($u);
    return $users;
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

/**
 * Добавляет или обновляет запись в recipients для пользователя.
 * Идентификация идёт по FullName = Username (как и в существующей синхронизации).
 *
 * @param mysqli $conn Открытое соединение с БД
 * @param string $oldUsername старое имя (нужно при переименовании)
 * @param string $newUsername новое имя пользователя
 * @param string $phone номер телефона (должен быть непустым для вставки)
 * @param int|null $groupID группа (nullable)
 */
function upsertRecipientForUser(mysqli $conn, string $oldUsername, string $newUsername, string $phone, ?int $groupID): void {
    $oldUsername = trim($oldUsername);
    $newUsername = trim($newUsername);
    $phone = trim($phone);
    if ($newUsername === '' || $phone === '') {
        // recipients.PhoneNumber NOT NULL — без телефона запись не создаём
        return;
    }

    $check = $conn->prepare("SELECT RecipientID FROM recipients WHERE FullName = ? LIMIT 1");
    $check->bind_param("s", $oldUsername);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();

    if ($existing && isset($existing['RecipientID'])) {
        $rid = (int)$existing['RecipientID'];
        if ($groupID === null) {
            $stmt = $conn->prepare("UPDATE recipients SET PhoneNumber = ?, FullName = ? WHERE RecipientID = ?");
            $stmt->bind_param("ssi", $phone, $newUsername, $rid);
        } else {
            $stmt = $conn->prepare("UPDATE recipients SET PhoneNumber = ?, FullName = ?, GroupID = ? WHERE RecipientID = ?");
            $stmt->bind_param("ssii", $phone, $newUsername, $groupID, $rid);
        }
        @$stmt->execute();
        $stmt->close();
        return;
    }

    // Если по старому имени не нашли — пробуем по новому (на случай повторного апсёрта)
    $check2 = $conn->prepare("SELECT RecipientID FROM recipients WHERE FullName = ? LIMIT 1");
    $check2->bind_param("s", $newUsername);
    $check2->execute();
    $existing2 = $check2->get_result()->fetch_assoc();
    $check2->close();
    if ($existing2 && isset($existing2['RecipientID'])) {
        $rid = (int)$existing2['RecipientID'];
        if ($groupID === null) {
            $stmt = $conn->prepare("UPDATE recipients SET PhoneNumber = ? WHERE RecipientID = ?");
            $stmt->bind_param("si", $phone, $rid);
        } else {
            $stmt = $conn->prepare("UPDATE recipients SET PhoneNumber = ?, GroupID = ? WHERE RecipientID = ?");
            $stmt->bind_param("sii", $phone, $groupID, $rid);
        }
        @$stmt->execute();
        $stmt->close();
        return;
    }

    // Вставка
    if ($groupID === null) {
        $stmt = $conn->prepare("INSERT INTO recipients (PhoneNumber, FullName, GroupID) VALUES (?, ?, NULL)");
        $stmt->bind_param("ss", $phone, $newUsername);
    } else {
        $stmt = $conn->prepare("INSERT INTO recipients (PhoneNumber, FullName, GroupID) VALUES (?, ?, ?)");
        $stmt->bind_param("ssi", $phone, $newUsername, $groupID);
    }
    @$stmt->execute();
    $stmt->close();
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

function updateUserById($id, $username, $password, $role, $status, $phoneNumber = '', $groupID = null) {
    try {
        $conn = connectToDatabase();
        
        // Проверяем, существует ли пользователь
        $check_stmt = $conn->prepare("SELECT UserID, Username FROM users WHERE UserID = ?");
        $check_stmt->bind_param("i", $id);
        $check_stmt->execute();
        $existing = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        
        if (!$existing) {
            $conn->close();
            return ['success' => false, 'message' => 'Пользователь не найден'];
        }
        $oldUsername = strval($existing['Username'] ?? '');
        
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
        
        $groupIDVal = ($groupID !== null && $groupID !== '') ? intval($groupID) : -1;
        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET Username = ?, PasswordHash = ?, Role = ?, Status = ?, PhoneNumber = ?, GroupID = NULLIF(?, -1) WHERE UserID = ?");
            $stmt->bind_param("sssssii", $username, $hashed_password, $role, $status, $normalizedPhone, $groupIDVal, $id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET Username = ?, Role = ?, Status = ?, PhoneNumber = ?, GroupID = NULLIF(?, -1) WHERE UserID = ?");
            $stmt->bind_param("ssssii", $username, $role, $status, $normalizedPhone, $groupIDVal, $id);
        }
        
        $ok = $stmt->execute();
        $stmt->close();
        
        if ($ok) {
            // Автообновление recipients для пользователя (включая переименование)
            $gidForRecipient = ($groupID !== null && $groupID !== '') ? intval($groupID) : null;
            upsertRecipientForUser($conn, $oldUsername, $username, $normalizedPhone, $gidForRecipient);
            $conn->close();

            $phoneInfo = !empty($normalizedPhone) ? ', телефон: ' . $normalizedPhone : '';
            logSystemAction('users', 'update', 'Обновлен пользователь ID=' . $id . ', имя: ' . $username . ', роль: ' . $role . $phoneInfo);
            return ['success' => true, 'message' => 'Пользователь успешно обновлен'];
        } else {
            $conn->close();
            return ['success' => false, 'message' => 'Не удалось обновить пользователя'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Ошибка обновления: ' . $e->getMessage()];
    }
}


// Журнал действий системы
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
        error_log('admin ensureSystemLogsTable failed: ' . $conn->error);
    }
}

function logSystemAction($category, $action, $details = '') {
    try {
        $conn = connectToDatabase();
        ensureSystemLogsTable($conn);
        $conn->set_charset('utf8mb4');
        $user = function_exists('getCurrentUser') ? getCurrentUser() : null;
        $performedBy = is_array($user) && isset($user['username']) ? $user['username'] : 'system';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt = $conn->prepare("INSERT INTO system_logs (Category, Action, Details, PerformedBy, IPAddress) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            error_log('admin logSystemAction prepare failed: ' . $conn->error);
            $conn->close();
            return;
        }
        $stmt->bind_param("sssss", $category, $action, $details, $performedBy, $ip);
        if (!$stmt->execute()) {
            error_log('admin logSystemAction execute failed: ' . $stmt->error);
        }
        $stmt->close();
        $conn->close();
    } catch (Exception $e) {
        error_log('admin logSystemAction exception: ' . $e->getMessage());
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

// Бекап базы данных (JSON файлы в папке backups) — актуальная структура БД
function createDatabaseBackup() {
    try {
        $conn = connectToDatabase();
        $data = [];
        $tables = [
            'groups' => 'SELECT * FROM groups',
            'recipients' => 'SELECT * FROM recipients',
            'messages' => 'SELECT * FROM messages',
            'messagelogs' => 'SELECT * FROM messagelogs ORDER BY LogID DESC',
            'users' => 'SELECT * FROM users',
            'companies' => 'SELECT * FROM companies',
            'user_companies' => 'SELECT * FROM user_companies',
            'user_messages' => 'SELECT * FROM user_messages ORDER BY UserMessageID DESC',
            'system_logs' => 'SELECT * FROM system_logs ORDER BY LogID DESC LIMIT 5000',
            'sms_templates' => 'SELECT * FROM sms_templates ORDER BY TemplateID DESC',
            'sms_checkbox_templates' => 'SELECT * FROM sms_checkbox_templates ORDER BY CheckboxTemplateID DESC',
        ];
        $res = $conn->query("SHOW TABLES LIKE 'user_feedback'");
        if ($res && $res->num_rows > 0) {
            $tables['user_feedback'] = 'SELECT * FROM user_feedback ORDER BY CreatedAt DESC';
        }
        $res = $conn->query("SHOW TABLES LIKE 'sms_settings'");
        if ($res && $res->num_rows > 0) {
            $tables['sms_settings'] = 'SELECT * FROM sms_settings';
        }

        foreach ($tables as $name => $sql) {
            $rows = [];
            if ($result = @$conn->query($sql)) {
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
            'companies' => ($_POST['import_companies'] ?? '1') === '1',
            'user_companies' => ($_POST['import_user_companies'] ?? '1') === '1',
            'user_messages' => ($_POST['import_user_messages'] ?? '1') === '1',
            'system_logs' => ($_POST['import_system_logs'] ?? '0') === '1',
            'sms_templates' => ($_POST['import_sms_templates'] ?? '1') === '1',
            'sms_checkbox_templates' => ($_POST['import_sms_checkbox_templates'] ?? '1') === '1',
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

        // REPLACE mode: clear tables in safe order (FK: user_messages -> messages; messagelogs -> messages)
        if ($mode === 'replace') {
            if ($opts['user_messages']) { @$conn->query('DELETE FROM user_messages'); }
            if ($opts['logs']) { @$conn->query('DELETE FROM messagelogs'); }
            if ($opts['messages']) { @$conn->query('DELETE FROM messages'); }
            if ($opts['recipients']) { @$conn->query('DELETE FROM recipients'); }
            if ($opts['groups']) { @$conn->query('DELETE FROM groups'); }
            if ($opts['feedback']) { $r = @$conn->query("SHOW TABLES LIKE 'user_feedback'"); if ($r && $r->num_rows > 0) @$conn->query('DELETE FROM user_feedback'); }
            if ($opts['system_logs']) { $r = @$conn->query("SHOW TABLES LIKE 'system_logs'"); if ($r && $r->num_rows > 0) @$conn->query('DELETE FROM system_logs'); }
            if ($opts['user_companies']) { @$conn->query('DELETE FROM user_companies'); }
            if ($opts['sms_templates']) { $r = @$conn->query("SHOW TABLES LIKE 'sms_templates'"); if ($r && $r->num_rows > 0) @$conn->query('DELETE FROM sms_templates'); }
            if ($opts['sms_checkbox_templates']) { $r = @$conn->query("SHOW TABLES LIKE 'sms_checkbox_templates'"); if ($r && $r->num_rows > 0) @$conn->query('DELETE FROM sms_checkbox_templates'); }
            if ($opts['companies']) { @$conn->query('DELETE FROM companies'); }
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

        // Users (append only for safety) — полная структура БД
        if ($opts['users'] && !empty($data['users']) && is_array($data['users'])) {
            foreach ($data['users'] as $row) {
                $uid = intval($row['UserID'] ?? 0);
                $un = strval($row['Username'] ?? '');
                if ($uid <= 0 || $un === '') { $result['skipped'][] = ['users' => $row]; continue; }
                $phone = isset($row['PhoneNumber']) ? $row['PhoneNumber'] : null;
                $hash = strval($row['PasswordHash'] ?? '');
                if ($hash === '') { $hash = password_hash('change_me_after_restore', PASSWORD_DEFAULT); }
                $role = strval($row['Role'] ?? 'user');
                $st = strval($row['Status'] ?? 'active');
                $gid = isset($row['GroupID']) && $row['GroupID'] !== '' && $row['GroupID'] !== null ? intval($row['GroupID']) : null;
                $pwdAt = strval($row['PasswordCreatedAt'] ?? date('Y-m-d H:i:s'));
                $failCnt = intval($row['FailedLoginCount'] ?? 0);
                $lastFail = isset($row['LastFailedLoginAt']) && $row['LastFailedLoginAt'] !== '' && $row['LastFailedLoginAt'] !== null ? strval($row['LastFailedLoginAt']) : null;
                $created = strval($row['CreatedAt'] ?? date('Y-m-d H:i:s'));
                $updated = strval($row['UpdatedAt'] ?? date('Y-m-d H:i:s'));
                if ($gid === null) {
                    $stmt = $conn->prepare('INSERT INTO users (UserID, Username, PhoneNumber, PasswordHash, Role, GroupID, Status, PasswordCreatedAt, FailedLoginCount, LastFailedLoginAt, CreatedAt, UpdatedAt) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)');
                    $stmt->bind_param('issssssisss', $uid, $un, $phone, $hash, $role, $st, $pwdAt, $failCnt, $lastFail, $created, $updated);
                } else {
                    $stmt = $conn->prepare('INSERT INTO users (UserID, Username, PhoneNumber, PasswordHash, Role, GroupID, Status, PasswordCreatedAt, FailedLoginCount, LastFailedLoginAt, CreatedAt, UpdatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->bind_param('issssisissss', $uid, $un, $phone, $hash, $role, $gid, $st, $pwdAt, $failCnt, $lastFail, $created, $updated);
                }
                @$stmt->execute();
            }
            $result['inserted']['users'] = count($data['users']);
        }

        // Companies
        if ($opts['companies'] && !empty($data['companies']) && is_array($data['companies'])) {
            $stmt = $conn->prepare('INSERT INTO companies (CompanyID, CompanyName) VALUES (?, ?)');
            foreach ($data['companies'] as $row) {
                $cid = intval($row['CompanyID'] ?? 0);
                $cname = strval($row['CompanyName'] ?? '');
                if ($cid <= 0 || $cname === '') { $result['skipped'][] = ['companies' => $row]; continue; }
                $stmt->bind_param('is', $cid, $cname);
                @$stmt->execute();
            }
            $result['inserted']['companies'] = count($data['companies']);
        }

        // User_companies
        if ($opts['user_companies'] && !empty($data['user_companies']) && is_array($data['user_companies'])) {
            $stmt = $conn->prepare('INSERT INTO user_companies (UserID, CompanyID) VALUES (?, ?)');
            foreach ($data['user_companies'] as $row) {
                $uid = intval($row['UserID'] ?? 0);
                $cid = intval($row['CompanyID'] ?? 0);
                if ($uid <= 0 || $cid <= 0) { $result['skipped'][] = ['user_companies' => $row]; continue; }
                $stmt->bind_param('ii', $uid, $cid);
                @$stmt->execute();
            }
            $result['inserted']['user_companies'] = count($data['user_companies']);
        }

        // User_messages
        if ($opts['user_messages'] && !empty($data['user_messages']) && is_array($data['user_messages'])) {
            $stmtWith = $conn->prepare('INSERT INTO user_messages (UserMessageID, SenderID, MessageID, RecipientID, SentDate, ReadStatus, ReadDate) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmtNull = $conn->prepare('INSERT INTO user_messages (UserMessageID, SenderID, MessageID, RecipientID, SentDate, ReadStatus, ReadDate) VALUES (?, ?, ?, ?, ?, ?, NULL)');
            foreach ($data['user_messages'] as $row) {
                $umid = intval($row['UserMessageID'] ?? 0);
                $sid = intval($row['SenderID'] ?? 0);
                $mid = intval($row['MessageID'] ?? 0);
                $rid = intval($row['RecipientID'] ?? 0);
                $sent = strval($row['SentDate'] ?? date('Y-m-d H:i:s'));
                $readSt = strval($row['ReadStatus'] ?? 'unread');
                $readDt = isset($row['ReadDate']) && $row['ReadDate'] !== '' && $row['ReadDate'] !== null ? strval($row['ReadDate']) : null;
                if ($umid <= 0 || $mid <= 0 || $rid <= 0) { $result['skipped'][] = ['user_messages' => $row]; continue; }
                if ($readDt !== null) {
                    $stmtWith->bind_param('iiiisss', $umid, $sid, $mid, $rid, $sent, $readSt, $readDt);
                    @$stmtWith->execute();
                } else {
                    $stmtNull->bind_param('iiiiss', $umid, $sid, $mid, $rid, $sent, $readSt);
                    @$stmtNull->execute();
                }
            }
            $result['inserted']['user_messages'] = count($data['user_messages']);
        }

        // System_logs
        if ($opts['system_logs'] && !empty($data['system_logs']) && is_array($data['system_logs'])) {
            $res = $conn->query("SHOW TABLES LIKE 'system_logs'");
            if ($res && $res->num_rows > 0) {
                $stmt = $conn->prepare('INSERT INTO system_logs (LogID, Category, Action, Details, PerformedBy, IPAddress, CreatedAt) VALUES (?, ?, ?, ?, ?, ?, ?)');
                foreach ($data['system_logs'] as $row) {
                    $lid = intval($row['LogID'] ?? 0);
                    $cat = strval($row['Category'] ?? '');
                    $act = strval($row['Action'] ?? '');
                    $det = isset($row['Details']) ? $row['Details'] : null;
                    $by = isset($row['PerformedBy']) ? $row['PerformedBy'] : null;
                    $ip = isset($row['IPAddress']) ? $row['IPAddress'] : null;
                    $dt = strval($row['CreatedAt'] ?? date('Y-m-d H:i:s'));
                    if ($lid <= 0) { $result['skipped'][] = ['system_logs' => $row]; continue; }
                    $stmt->bind_param('issssss', $lid, $cat, $act, $det, $by, $ip, $dt);
                    @$stmt->execute();
                }
                $result['inserted']['system_logs'] = count($data['system_logs']);
            }
        }

        // Sms_templates (TemplateID, CompanyID, TemplateName, TemplateText, CreatedAt, UpdatedAt; опционально UserID)
        if ($opts['sms_templates'] && !empty($data['sms_templates']) && is_array($data['sms_templates'])) {
            $res = $conn->query("SHOW TABLES LIKE 'sms_templates'");
            if ($res && $res->num_rows > 0) {
                $hasUserID = false;
                $cols = $conn->query("SHOW COLUMNS FROM sms_templates LIKE 'UserID'");
                if ($cols && $cols->num_rows > 0) $hasUserID = true;
                foreach ($data['sms_templates'] as $row) {
                    $tid = intval($row['TemplateID'] ?? 0);
                    $cid = intval($row['CompanyID'] ?? 0);
                    $tname = strval($row['TemplateName'] ?? '');
                    $ttext = strval($row['TemplateText'] ?? '');
                    $created = strval($row['CreatedAt'] ?? date('Y-m-d H:i:s'));
                    $updated = strval($row['UpdatedAt'] ?? date('Y-m-d H:i:s'));
                    if ($tid <= 0 || $cid <= 0 || $tname === '') { $result['skipped'][] = ['sms_templates' => $row]; continue; }
                    if ($hasUserID) {
                        $uid = isset($row['UserID']) && $row['UserID'] !== '' && $row['UserID'] !== null ? intval($row['UserID']) : null;
                        $stmt = $conn->prepare('INSERT INTO sms_templates (TemplateID, CompanyID, UserID, TemplateName, TemplateText, CreatedAt, UpdatedAt) VALUES (?, ?, ?, ?, ?, ?, ?)');
                        $stmt->bind_param('iiissss', $tid, $cid, $uid, $tname, $ttext, $created, $updated);
                    } else {
                        $stmt = $conn->prepare('INSERT INTO sms_templates (TemplateID, CompanyID, TemplateName, TemplateText, CreatedAt, UpdatedAt) VALUES (?, ?, ?, ?, ?, ?)');
                        $stmt->bind_param('iissss', $tid, $cid, $tname, $ttext, $created, $updated);
                    }
                    @$stmt->execute();
                }
                $result['inserted']['sms_templates'] = count($data['sms_templates']);
            }
        }

        // Sms_checkbox_templates
        if ($opts['sms_checkbox_templates'] && !empty($data['sms_checkbox_templates']) && is_array($data['sms_checkbox_templates'])) {
            $res = $conn->query("SHOW TABLES LIKE 'sms_checkbox_templates'");
            if ($res && $res->num_rows > 0) {
                $hasCreatedBy = false;
                $hasUpdatedBy = false;
                $cols = $conn->query("SHOW COLUMNS FROM sms_checkbox_templates");
                if ($cols) {
                    while ($c = $cols->fetch_assoc()) {
                        if ($c['Field'] === 'CreatedByUserID') $hasCreatedBy = true;
                        if ($c['Field'] === 'UpdatedByUserID') $hasUpdatedBy = true;
                    }
                }
                foreach ($data['sms_checkbox_templates'] as $row) {
                    $id = intval($row['CheckboxTemplateID'] ?? 0);
                    $cid = intval($row['CompanyID'] ?? 0);
                    $tname = strval($row['TemplateName'] ?? '');
                    $tdata = strval($row['TemplateData'] ?? '');
                    $created = strval($row['CreatedAt'] ?? date('Y-m-d H:i:s'));
                    $updated = strval($row['UpdatedAt'] ?? date('Y-m-d H:i:s'));
                    if ($id <= 0 || $cid <= 0 || $tname === '') { $result['skipped'][] = ['sms_checkbox_templates' => $row]; continue; }
                    if ($hasCreatedBy && $hasUpdatedBy) {
                        $cby = isset($row['CreatedByUserID']) && $row['CreatedByUserID'] !== '' && $row['CreatedByUserID'] !== null ? intval($row['CreatedByUserID']) : null;
                        $uby = isset($row['UpdatedByUserID']) && $row['UpdatedByUserID'] !== '' && $row['UpdatedByUserID'] !== null ? intval($row['UpdatedByUserID']) : null;
                        $stmt = $conn->prepare('INSERT INTO sms_checkbox_templates (CheckboxTemplateID, CompanyID, TemplateName, TemplateData, CreatedByUserID, UpdatedByUserID, CreatedAt, UpdatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                        $stmt->bind_param('iissiiss', $id, $cid, $tname, $tdata, $cby, $uby, $created, $updated);
                    } else {
                        $stmt = $conn->prepare('INSERT INTO sms_checkbox_templates (CheckboxTemplateID, CompanyID, TemplateName, TemplateData, CreatedAt, UpdatedAt) VALUES (?, ?, ?, ?, ?, ?)');
                        $stmt->bind_param('iissss', $id, $cid, $tname, $tdata, $created, $updated);
                    }
                    @$stmt->execute();
                }
                $result['inserted']['sms_checkbox_templates'] = count($data['sms_checkbox_templates']);
            }
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
            
            // Определяем группу на основе роли: admin -> Руководство(4), user/recipient -> Сотрудники(1)
            $group_id = ($user['Role'] === 'admin') ? 4 : 1;
            
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
    $isAdmin = ($user['role'] ?? '') === 'admin';

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
            // Администратор отправляет только личные сообщения (без SMS)
            if (!$isAdmin) {
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
                
                $companyName = getSelectedCompanyName();
                $messageWithSender = $message_text;
                if (!empty($companyName) && mb_strlen($message_text . ' ' . $companyName) <= 600) {
                    $messageWithSender = $message_text . ' ' . $companyName;
                }
                
                $smsResult = sendSms($recipient['PhoneNumber'], $messageWithSender);
                $smsStatus = (string)($smsResult['status'] ?? ($smsResult['success'] ? 'Отправлено' : 'Ошибка'));

                $stmt = $conn->prepare("INSERT INTO messagelogs (MessageID, RecipientID, Status, SentDate) VALUES (?, ?, ?, NOW())");
                $stmt->bind_param("iis", $messageId, $recipient_id, $smsStatus);
                $stmt->execute();
                $stmt->close();
            }
            
            $stmt = $conn->prepare("INSERT INTO user_messages (SenderID, MessageID, RecipientID) VALUES (?, ?, ?)");
            $stmt->bind_param("iii", $sender_id, $messageId, $recipient_id);
            $ok = $stmt->execute();
            $stmt->close();

            if ($ok) {
                if ($isAdmin) {
                    $sent++;
                } else {
                    $stmt = $conn->prepare("SELECT 1 FROM messagelogs WHERE MessageID = ? AND RecipientID = ?");
                    $stmt->bind_param("ii", $messageId, $recipient_id);
                    $stmt->execute();
                    $hasLog = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($hasLog) $sent++;
                    else $errors++;
                }
            } else {
                $errors++;
            }
        }

        $conn->commit();
        $logAction = $isAdmin ? 'personal_message' : 'sms_send';
        $logMessage = $isAdmin
            ? ('Личное сообщение: ' . $preview . '; получателей: ' . count($recipients))
            : ('SMS: ' . $preview . '; получателей: ' . count($recipients) . '; отправлено: ' . $sent . '; ошибки: ' . $errors);
        logSystemAction('user', $logAction, $logMessage);
        $conn->close();
        return ['success' => true, 'message' => $isAdmin ? "Сообщение отправлено: $sent получателей" : "Отправлено: $sent, ошибок: $errors"];
    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollback();
            $conn->close();
        }
        logSystemAction('user', 'sms_error', 'Исключение: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Ошибка отправки SMS: ' . $e->getMessage()];
    }
}

function fetchUserMessages($dateFilter = 'all') {
    $conn = connectToDatabase();
    $user = getCurrentUser();
    $current_user_id = intval($user['id'] ?? 0);
    $current_username = $user['username'] ?? '';
    $dateCondition = '';
    $paramTypes = 'i';
    $params = [$current_user_id];
    if ($dateFilter === 'day') {
        $dateCondition = ' AND um.SentDate >= DATE_SUB(NOW(), INTERVAL 1 DAY)';
    } elseif ($dateFilter === 'week') {
        $dateCondition = ' AND um.SentDate >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
    } elseif ($dateFilter === 'month') {
        $dateCondition = ' AND um.SentDate >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
    }
    $sent_messages = [];
    $sql = "
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
        WHERE um.SenderID = ? $dateCondition
        ORDER BY um.SentDate DESC 
        LIMIT 100
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $sent_messages[] = $row; }
    $stmt->close();
    $received_messages = [];
    $sql = "
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
        WHERE r.FullName = ? $dateCondition
        ORDER BY um.SentDate DESC 
        LIMIT 100
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $current_username);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $received_messages[] = $row; }
    $stmt->close();
    $conn->close();
    return ['sent' => $sent_messages, 'received' => $received_messages];
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

// Функции для работы с шаблонами SMS (UserID = владелец шаблона, у пользователей видны только свои)
function ensureSmsTemplatesTable($conn) {
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
    $r = $conn->query("SHOW COLUMNS FROM sms_templates LIKE 'UserID'");
    if ($r && $r->num_rows == 0) {
        @$conn->query("ALTER TABLE sms_templates ADD COLUMN UserID INT UNSIGNED NULL AFTER CompanyID");
    }
}

function addSmsTemplate($data) {
    try {
        $templateName = trim($data['template_name'] ?? '');
        $templateText = trim($data['template_text'] ?? '');
        $companyIds = isset($data['company_ids']) ? (is_array($data['company_ids']) ? $data['company_ids'] : []) : [];
        $companyIds = array_map('intval', $companyIds);
        $companyIds = array_filter($companyIds, function ($id) { return $id > 0; });
        $companyIds = array_values(array_unique($companyIds));
        
        if (empty($templateName)) {
            return ['success' => false, 'message' => 'Введите название шаблона'];
        }
        
        if (empty($templateText)) {
            return ['success' => false, 'message' => 'Введите текст шаблона'];
        }
        
        if (empty($companyIds)) {
            return ['success' => false, 'message' => 'Выберите хотя бы одно предприятие для сохранения шаблона'];
        }
        
        $conn = connectToDatabase();
        ensureSmsTemplatesTable($conn);
        $currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $stmt = $conn->prepare("INSERT INTO sms_templates (CompanyID, UserID, TemplateName, TemplateText) VALUES (?, ?, ?, ?)");
        $added = 0;
        foreach ($companyIds as $companyId) {
            $stmt->bind_param("iiss", $companyId, $currentUserId, $templateName, $templateText);
            if ($stmt->execute()) {
                $added++;
            }
        }
        $stmt->close();
        $conn->close();
        
        if ($added > 0) {
            logSystemAction('sms_templates', 'add', 'Добавлен шаблон SMS: ' . $templateName . ' на ' . $added . ' предприятий');
            return ['success' => true, 'message' => 'Шаблон успешно добавлен на ' . $added . ' предприятий'];
        }
        return ['success' => false, 'message' => 'Ошибка при добавлении шаблона'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()];
    }
}

function updateSmsTemplate($data) {
    try {
        $templateId = intval($data['template_id'] ?? 0);
        $companyId = isset($data['company_id']) ? intval($data['company_id']) : getSelectedCompany();
        
        if ($templateId <= 0) {
            return ['success' => false, 'message' => 'Некорректный ID шаблона'];
        }
        
        if (!$companyId) {
            return ['success' => false, 'message' => 'Предприятие не указано'];
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
        
        $check_stmt = $conn->prepare("SELECT TemplateID FROM sms_templates WHERE TemplateID = ? AND CompanyID = ?");
        $check_stmt->bind_param("ii", $templateId, $companyId);
        $check_stmt->execute();
        $exists = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        
        if (!$exists) {
            $conn->close();
            return ['success' => false, 'message' => 'Шаблон не найден или не принадлежит указанному предприятию'];
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
        $companyId = isset($data['company_id']) ? intval($data['company_id']) : getSelectedCompany();
        
        if ($templateId <= 0) {
            return ['success' => false, 'message' => 'Некорректный ID шаблона'];
        }
        
        if (!$companyId) {
            return ['success' => false, 'message' => 'Предприятие не указано'];
        }
        
        $conn = connectToDatabase();
        ensureSmsTemplatesTable($conn);
        
        $check_stmt = $conn->prepare("SELECT TemplateName FROM sms_templates WHERE TemplateID = ? AND CompanyID = ?");
        $check_stmt->bind_param("ii", $templateId, $companyId);
        $check_stmt->execute();
        $template = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        
        if (!$template) {
            $conn->close();
            return ['success' => false, 'message' => 'Шаблон не найден или не принадлежит указанному предприятию'];
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

// Все шаблоны с названием предприятия (для админки)
function fetchSmsTemplatesAll() {
    try {
        $conn = connectToDatabase();
        ensureSmsTemplatesTable($conn);
        
        $sql = "SELECT t.TemplateID, t.CompanyID, t.TemplateName, t.TemplateText, t.CreatedAt, t.UpdatedAt, t.UserID, c.CompanyName,
                u.Username AS CreatedByUsername
                FROM sms_templates t 
                LEFT JOIN companies c ON t.CompanyID = c.CompanyID
                LEFT JOIN users u ON t.UserID = u.UserID
                ORDER BY c.CompanyName, t.TemplateID DESC";
        $result = $conn->query($sql);
        $templates = [];
        while ($row = $result->fetch_assoc()) {
            $templates[] = $row;
        }
        $conn->close();
        return $templates;
    } catch (Exception $e) {
        return [];
    }
}

function getSmsTemplateById($id, $companyId = null) {
    try {
        if (!$companyId) {
            $companyId = getSelectedCompany();
        }
        if (!$companyId) {
            return null;
        }
        
        $conn = connectToDatabase();
        ensureSmsTemplatesTable($conn);
        
        $stmt = $conn->prepare("SELECT TemplateID, CompanyID, TemplateName, TemplateText, CreatedAt, UpdatedAt FROM sms_templates WHERE TemplateID = ? AND CompanyID = ?");
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

// Чекбокс-шаблоны SMS
function ensureCheckboxTemplatesTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS sms_checkbox_templates (
        CheckboxTemplateID INT AUTO_INCREMENT PRIMARY KEY,
        CompanyID INT NOT NULL,
        TemplateName VARCHAR(255) NOT NULL,
        TemplateData TEXT NOT NULL,
        CreatedByUserID INT UNSIGNED NULL,
        UpdatedByUserID INT UNSIGNED NULL,
        CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY CompanyID (CompanyID)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Добавить колонки если их нет (миграция)
    $cols = [];
    $res = @$conn->query("SHOW COLUMNS FROM sms_checkbox_templates");
    if ($res) { while ($r = $res->fetch_assoc()) $cols[$r['Field']] = true; }
    if (!isset($cols['CreatedByUserID'])) @$conn->query("ALTER TABLE sms_checkbox_templates ADD COLUMN CreatedByUserID INT UNSIGNED NULL AFTER TemplateData");
    if (!isset($cols['UpdatedByUserID'])) @$conn->query("ALTER TABLE sms_checkbox_templates ADD COLUMN UpdatedByUserID INT UNSIGNED NULL AFTER CreatedByUserID");
}

function fetchCheckboxTemplatesAll($companyFilter = null) {
    try {
        $conn = connectToDatabase();
        ensureCheckboxTemplatesTable($conn);
        $where = $companyFilter ? " WHERE t.CompanyID = " . intval($companyFilter) : '';
        $sql = "SELECT t.CheckboxTemplateID, t.CompanyID, t.TemplateName, t.TemplateData, t.CreatedAt, t.UpdatedAt, t.CreatedByUserID, t.UpdatedByUserID,
                c.CompanyName,
                u_created.Username AS CreatedByUsername,
                u_updated.Username AS UpdatedByUsername
                FROM sms_checkbox_templates t 
                LEFT JOIN companies c ON t.CompanyID = c.CompanyID
                LEFT JOIN users u_created ON t.CreatedByUserID = u_created.UserID
                LEFT JOIN users u_updated ON t.UpdatedByUserID = u_updated.UserID
                $where
                ORDER BY c.CompanyName, t.UpdatedAt DESC, t.CheckboxTemplateID DESC";
        $result = $conn->query($sql);
        $templates = [];
        while ($row = $result->fetch_assoc()) {
            $templates[] = $row;
        }
        $conn->close();
        return $templates;
    } catch (Exception $e) {
        return [];
    }
}

function getCheckboxTemplateById($id, $companyId = null) {
    try {
        if (!$companyId) {
            $companyId = getSelectedCompany();
        }
        if (!$companyId || $id <= 0) {
            return null;
        }
        $conn = connectToDatabase();
        ensureCheckboxTemplatesTable($conn);
        $stmt = $conn->prepare("SELECT CheckboxTemplateID, CompanyID, TemplateName, TemplateData, CreatedAt, UpdatedAt FROM sms_checkbox_templates WHERE CheckboxTemplateID = ? AND CompanyID = ?");
        $stmt->bind_param("ii", $id, $companyId);
        $stmt->execute();
        $template = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $conn->close();
        return $template;
    } catch (Exception $e) {
        return null;
    }
}

function saveCheckboxTemplate($data) {
    try {
        $id = intval($data['checkbox_template_id'] ?? 0);
        $templateName = trim($data['template_name'] ?? '');
        $companyIds = isset($data['company_ids']) ? (is_array($data['company_ids']) ? $data['company_ids'] : []) : [];
        $companyIds = array_values(array_unique(array_filter(array_map('intval', $companyIds), function ($i) { return $i > 0; })));
        $templateData = $data['template_data'] ?? '';

        if (empty($templateName)) {
            return ['success' => false, 'message' => 'Введите название чекбокс-шаблона'];
        }
        if (empty($templateData)) {
            return ['success' => false, 'message' => 'Данные шаблона пусты'];
        }
        $decoded = json_decode($templateData, true);
        if (!is_array($decoded) || !isset($decoded['columns']) || !isset($decoded['rows'])) {
            return ['success' => false, 'message' => 'Некорректная структура данных шаблона'];
        }
        if (empty($companyIds)) {
            return ['success' => false, 'message' => 'Выберите хотя бы одно предприятие'];
        }

        $conn = connectToDatabase();
        ensureCheckboxTemplatesTable($conn);
        $currentUserId = intval(getCurrentUser()['id'] ?? 0);

        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE sms_checkbox_templates SET TemplateName = ?, TemplateData = ?, CompanyID = ?, UpdatedByUserID = ? WHERE CheckboxTemplateID = ?");
            $cid = $companyIds[0];
            $uidVal = $currentUserId > 0 ? $currentUserId : null;
            $stmt->bind_param("ssiii", $templateName, $templateData, $cid, $uidVal, $id);
            if ($stmt->execute()) {
                logSystemAction('checkbox_templates', 'update', 'Обновлен чекбокс-шаблон ID=' . $id . ': ' . $templateName);
                $stmt->close();
                $conn->close();
                return ['success' => true, 'message' => 'Шаблон обновлен'];
            }
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO sms_checkbox_templates (CompanyID, TemplateName, TemplateData, CreatedByUserID, UpdatedByUserID) VALUES (?, ?, ?, ?, ?)");
            $added = 0;
            $uidVal = $currentUserId > 0 ? $currentUserId : null;
            foreach ($companyIds as $cid) {
                $stmt->bind_param("issii", $cid, $templateName, $templateData, $uidVal, $uidVal);
                if ($stmt->execute()) {
                    $added++;
                }
            }
            $stmt->close();
            if ($added > 0) {
                logSystemAction('checkbox_templates', 'add', 'Добавлен чекбокс-шаблон: ' . $templateName . ' на ' . $added . ' предприятий');
                $conn->close();
                return ['success' => true, 'message' => 'Шаблон добавлен на ' . $added . ' предприятий'];
            }
        }
        $conn->close();
        return ['success' => false, 'message' => 'Ошибка сохранения'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()];
    }
}

function deleteCheckboxTemplate($data) {
    try {
        $id = intval($data['id'] ?? 0);
        $companyId = isset($data['company_id']) ? intval($data['company_id']) : getSelectedCompany();
        if ($id <= 0 || !$companyId) {
            return ['success' => false, 'message' => 'Некорректные данные'];
        }
        $conn = connectToDatabase();
        ensureCheckboxTemplatesTable($conn);
        $stmt = $conn->prepare("DELETE FROM sms_checkbox_templates WHERE CheckboxTemplateID = ? AND CompanyID = ?");
        $stmt->bind_param("ii", $id, $companyId);
        $ok = $stmt->execute();
        $stmt->close();
        $conn->close();
        if ($ok) {
            logSystemAction('checkbox_templates', 'delete', 'Удален чекбокс-шаблон ID=' . $id);
            return ['success' => true, 'message' => 'Шаблон удален'];
        }
        return ['success' => false, 'message' => 'Не удалось удалить'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()];
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
        .checkbox-template-col-header {
            white-space: nowrap;
            min-width: 80px;
        }
        .checkbox-template-col-header .editable-cell {
            display: inline-block;
            min-width: 40px;
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

        /* Модальное окно редактирования пользователя — можно листать при длинной форме */
        #editUserModal {
            overflow-y: auto;
            overflow-x: hidden;
            padding: 20px 0;
        }
        #editUserModal .modal-content {
            margin: 0 auto 20px;
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
                        <i class="fas fa-comments"></i> Личные сообщения
                    </button>
                    <button class="tab" onclick="showTab('sent_received')">
                        <i class="fas fa-inbox"></i> Личные сообщения
                    </button>
                    <button class="tab" onclick="showTab('sms_templates')">
                        <i class="fas fa-file-alt"></i> Создание шаблона SMS
                    </button>
                    <button class="tab" onclick="showTab('companies')">
                        <i class="fas fa-building"></i> Предприятия
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
                        <label><input type="checkbox" id="impLogs" checked> Логи отправок (messagelogs)</label>
                        <label><input type="checkbox" id="impFeedback" checked> Обратная связь</label>
                        <label><input type="checkbox" id="impCompanies" checked> Предприятия</label>
                        <label><input type="checkbox" id="impUserCompanies" checked> Привязка пользователей к предприятиям</label>
                        <label><input type="checkbox" id="impUserMessages" checked> Личные сообщения (user_messages)</label>
                        <label><input type="checkbox" id="impSystemLogs"> Журнал системы (system_logs)</label>
                        <label><input type="checkbox" id="impSmsTemplates" checked> Шаблоны SMS</label>
                        <label><input type="checkbox" id="impSmsCheckboxTemplates" checked> Чек-бокс шаблоны</label>
                        <label><input type="checkbox" id="impUsers"> Пользователи (осторожно)</label>
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
                    <div class="form-group" id="newUserPasswordGroup">
                        <input type="text" id="newUserPassword" placeholder="Пароль (не требуется для Получателя)">
                    </div>
                    <div class="form-group">
                        <select id="newUserRole">
                            <option value="user">Пользователь</option>
                            <option value="admin">Администратор</option>
                            <option value="recipient">Получатель</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <input type="text" id="newUserPhone" placeholder="Номер телефона (опционально): +7XXXXXXXXXX или 8XXXXXXXXXX">
                        <small style="color: #666; font-size: 12px; margin-top: 5px; display: block;">
                            Номер будет автоматически нормализован. Формат: +7XXXXXXXXXX
                        </small>
                    </div>
                    <div class="form-group">
                        <label>Предприятия для пользователя (шапки предприятий):</label>
                        <p style="color: #666; font-size: 12px; margin-bottom: 8px;">Отметьте предприятия, к которым будет привязан пользователь. При входе он сможет выбрать одно из них для рассылки.</p>
                        <div id="newUserCompaniesCheckboxes"></div>
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
                    <!-- Администратор отправляет только личные сообщения (не СМС) -->
                    <div style="margin-bottom: 30px;">
                        <h3>📩 Отправка личного сообщения пользователям</h3>
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
                            <label for="adminFilterCompany">Фильтр по предприятию</label>
                            <select id="adminFilterCompany">
                                <option value="">Все предприятия</option>
                                <?php foreach (getCompanies() as $c): ?>
                                    <option value="<?php echo (int)$c['CompanyID']; ?>"><?php echo htmlspecialchars($c['CompanyName']); ?></option>
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
                            <textarea id="userMessageText" placeholder="Введите текст сообщения..." maxlength="600" disabled></textarea>
                            <div class="char-counter" id="userCharCounter">0/600 символов</div>
                        </div>
                        <button class="btn" onclick="sendUserMessage()">
                            <i class="fas fa-paper-plane"></i> Отправить сообщение
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
                    <div class="form-group" id="templateCompaniesBlock">
                        <label>Добавить шаблон на предприятия:</label>
                        <p style="color: #666; font-size: 12px; margin-bottom: 8px;">Выберите одно или несколько предприятий, на которые будет сохранён шаблон.</p>
                        <div id="templateCompaniesCheckboxes"></div>
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

                    <hr style="margin: 40px 0 20px; border: none; border-top: 1px solid #ddd;">
                    <h3>Чек-бокс шаблон СМС</h3>
                    <p style="color: #666; font-size: 13px; margin-bottom: 15px;">Создавайте табличные шаблоны: первый столбец — название (например «Лава»), остальные — заголовки данных (ad, wrt). В строках — подписи и значения. Пользователь выбирает нужные ячейки чекбоксами при рассылке.</p>
                    <div class="form-group">
                        <label for="checkboxTemplateName">Название чекбокс-шаблона:</label>
                        <input type="text" id="checkboxTemplateName" placeholder="Например: Лава">
                    </div>
                    <div class="form-group">
                        <label>Таблица данных (редактируемая):</label>
                        <div id="checkboxTemplateTableWrap" style="overflow-x: auto; border: 1px solid #ccc; border-radius: 8px;">
                            <table id="checkboxTemplateTable" class="data-table" style="min-width: 400px;">
                                <thead>
                                    <tr>
                                        <th contenteditable="true" class="editable-cell" data-row="0" data-col="0" placeholder="Название (Лава)">Лава</th>
                                        <th class="checkbox-template-col-header"><span contenteditable="true" class="editable-cell" data-row="0" data-col="1">ad</span><button type="button" class="btn btn-danger" style="padding: 2px 6px; font-size: 11px; margin-left: 4px;" onclick="removeCheckboxTemplateColumn(1)" title="Удалить столбец">&times;</button></th>
                                        <th class="checkbox-template-col-header"><span contenteditable="true" class="editable-cell" data-row="0" data-col="2">wrt</span><button type="button" class="btn btn-danger" style="padding: 2px 6px; font-size: 11px; margin-left: 4px;" onclick="removeCheckboxTemplateColumn(2)" title="Удалить столбец">&times;</button></th>
                                        <th style="width: 40px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td contenteditable="true" class="editable-cell" data-row="1" data-col="0">Плановые показатели качества</td>
                                        <td contenteditable="true" class="editable-cell" data-row="1" data-col="1">37,8</td>
                                        <td contenteditable="true" class="editable-cell" data-row="1" data-col="2">7,5</td>
                                        <td><button type="button" class="btn btn-danger" style="padding: 2px 8px; font-size: 12px;" onclick="removeCheckboxTemplateRow(this)">&times;</button></td>
                                    </tr>
                                    <tr>
                                        <td contenteditable="true" class="editable-cell" data-row="2" data-col="0">Фактические показатели:</td>
                                        <td contenteditable="true" class="editable-cell" data-row="2" data-col="1">37,8</td>
                                        <td contenteditable="true" class="editable-cell" data-row="2" data-col="2">6,6</td>
                                        <td><button type="button" class="btn btn-danger" style="padding: 2px 8px; font-size: 12px;" onclick="removeCheckboxTemplateRow(this)">&times;</button></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div style="margin-top: 8px;">
                            <button type="button" class="btn btn-secondary" onclick="addCheckboxTemplateRow()" style="font-size: 13px;"><i class="fas fa-plus"></i> Добавить строку</button>
                            <button type="button" class="btn btn-secondary" onclick="addCheckboxTemplateColumn()" style="font-size: 13px; margin-left: 8px;"><i class="fas fa-plus"></i> Добавить столбец</button>
                            <span style="color: #666; font-size: 12px; margin-left: 8px;">— удалить столбец: кнопка &times; в заголовке</span>
                        </div>
                    </div>
                    <div class="form-group" id="checkboxTemplateCompaniesBlock">
                        <label>Предприятия:</label>
                        <div id="checkboxTemplateCompaniesCheckboxes"></div>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn" onclick="saveCheckboxTemplate()" id="saveCheckboxTemplateBtn"><i class="fas fa-save"></i> Сохранить чекбокс-шаблон</button>
                        <button class="btn btn-secondary" onclick="clearCheckboxTemplateForm()" id="clearCheckboxTemplateBtn" style="display: none;"><i class="fas fa-times"></i> Отмена</button>
                    </div>
                    <div class="status-message" id="checkboxTemplateStatusMessage"></div>
                    <h3 style="margin-top: 30px;">Список чекбокс-шаблонов</h3>
                    <div class="form-group" style="margin-bottom: 15px;">
                        <label for="checkboxTemplateCompanyFilter">Фильтр по предприятию:</label>
                        <select id="checkboxTemplateCompanyFilter" onchange="loadCheckboxTemplates()">
                            <option value="">Все предприятия</option>
                        </select>
                    </div>
                    <div id="checkboxTemplatesTable"><div class="loading">Загрузка...</div></div>
                </div>

                <!-- Вкладка предприятий -->
                <div class="tab-content" id="companiesTab">
                    <h3>Добавить предприятие</h3>
                    <p style="color: #666; margin-bottom: 15px;">Создавайте предприятия (шапки), к которым затем можно привязывать пользователей и шаблоны SMS.</p>
                    <div class="form-group">
                        <label for="newCompanyName">Название предприятия:</label>
                        <input type="text" id="newCompanyName" placeholder="Например: ООО Ромашка">
                    </div>
                    <button class="btn" onclick="addCompanySubmit()">
                        <i class="fas fa-plus"></i> Добавить предприятие
                    </button>
                    <div class="status-message" id="companyStatusMessage"></div>
                    <h3 style="margin-top: 30px;">Список предприятий</h3>
                    <div id="companiesTable">
                        <div class="loading">Загрузка...</div>
                    </div>
                </div>

                <!-- Модальное окно редактирования предприятия -->
                <div id="editCompanyModal" class="modal" style="display: none;">
                    <div class="modal-content" style="max-width: 450px;">
                        <div class="modal-header">
                            <h3>Редактировать предприятие</h3>
                            <span class="close" onclick="closeEditCompanyModal()">&times;</span>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" id="editCompanyId">
                            <div class="form-group">
                                <label for="editCompanyName">Название предприятия:</label>
                                <input type="text" id="editCompanyName" placeholder="Название">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeEditCompanyModal()">Отмена</button>
                            <button type="button" class="btn" onclick="saveCompanyEdit()">Сохранить</button>
                        </div>
                    </div>
                </div>

                <!-- Вкладка: личные сообщения (отправленные и полученные) -->
                <div class="tab-content" id="sent_receivedTab">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 15px;">
                        <h3 style="margin: 0;">Личные сообщения</h3>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <button class="btn btn-danger" type="button" onclick="clearPersonalMessagesHistory()"><i class="fas fa-trash"></i> Очистить все</button>
                        </div>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="card" style="margin: 0;">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 10px;">
                                <h2 style="margin: 0;"><i class="fas fa-paper-plane"></i> Отправленные</h2>
                                <select id="adminMessageFilter" onchange="loadUserMessages()" style="padding: 6px 10px; border-radius: 6px;">
                                    <option value="all">Все</option>
                                    <option value="day">24 часа</option>
                                    <option value="week">7 дней</option>
                                    <option value="month">30 дней</option>
                                </select>
                            </div>
                            <div id="sentMessagesList" style="max-height: 600px; overflow-y: auto;">
                                <div class="loading">Загрузка...</div>
                            </div>
                        </div>
                        <div class="card" style="margin: 0;">
                            <h2><i class="fas fa-inbox"></i> Полученные</h2>
                            <div id="receivedMessagesList" style="max-height: 600px; overflow-y: auto;">
                                <div class="loading">Загрузка...</div>
                            </div>
                        </div>
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
                            <option value="recipient">Получатель</option>
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
                    <div class="form-group">
                        <label for="editGroupId">Группа:</label>
                        <select id="editGroupId">
                            <option value="">— Без группы —</option>
                            <?php foreach ($groups as $g): ?>
                            <option value="<?php echo (int)$g['GroupID']; ?>"><?php echo htmlspecialchars($g['GroupName']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Предприятия для пользователя (шапки предприятий):</label>
                        <p style="color: #666; font-size: 12px; margin-bottom: 8px;">Отметьте предприятия, к которым привязан пользователь. При входе он сможет выбрать одно для рассылки.</p>
                        <div id="editUserCompaniesCheckboxes"></div>
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
        let editingTemplateCompanyId = null;
        let companiesList = [];

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
            
            // Счетчик символов для пользовательских сообщений
            const userMessageTextEl = document.getElementById('userMessageText');
            if (userMessageTextEl) {
                userMessageTextEl.addEventListener('input', function() {
                    const length = this.value.length;
                    const counter = document.getElementById('userCharCounter');
                    if (counter) {
                        counter.textContent = `${length}/600 символов`;
                        if (length > 540) {
                            counter.className = 'char-counter warning';
                        } else if (length > 600) {
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
            form.append('import_companies', document.getElementById('impCompanies').checked ? '1' : '0');
            form.append('import_user_companies', document.getElementById('impUserCompanies').checked ? '1' : '0');
            form.append('import_user_messages', document.getElementById('impUserMessages').checked ? '1' : '0');
            form.append('import_system_logs', document.getElementById('impSystemLogs').checked ? '1' : '0');
            form.append('import_sms_templates', document.getElementById('impSmsTemplates').checked ? '1' : '0');
            form.append('import_sms_checkbox_templates', document.getElementById('impSmsCheckboxTemplates').checked ? '1' : '0');
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
                    users = data.data || [];
                    companiesList = data.companies || [];
                    displayUsersTable();
                    fillNewUserCompaniesCheckboxes();
                    renderAdminRecipientsList();
                }
            })
            .catch(error => {
                console.error('Ошибка загрузки пользователей:', error);
            });
        }

        function fillNewUserCompaniesCheckboxes() {
            const box = document.getElementById('newUserCompaniesCheckboxes');
            if (!box) return;
            box.innerHTML = '';
            (companiesList || []).forEach(c => {
                box.innerHTML += `<label style="display:block;margin:8px 0;"><input type="checkbox" name="new_user_company_cb" value="${c.CompanyID}"> ${escapeHtml(c.CompanyName)}</label>`;
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

            let table = '<table class="data-table"><thead><tr><th>Имя</th><th>Телефон</th><th>Роль</th><th>Статус</th><th>Создан</th><th>Предприятие</th><th>Группа</th><th>Действия</th></tr></thead><tbody>';
            users.forEach(u => {
                const phone = u.PhoneNumber || '—';
                const companyNames = (u.CompanyNames || '').replace(/'/g, "\\'");
                const groupName = (u.GroupName || '—').replace(/'/g, "\\'");
                table += `
                    <tr>
                        <td>${escapeHtml(u.Username)}</td>
                        <td>${escapeHtml(phone)}</td>
                        <td>${u.Role === 'admin' ? 'Администратор' : (u.Role === 'recipient' ? 'Получатель' : 'Пользователь')}</td>
                        <td>${u.Status}</td>
                        <td>${u.CreatedAt || ''}</td>
                        <td>${escapeHtml(u.CompanyNames || '—')}</td>
                        <td>${escapeHtml(u.GroupName || '—')}</td>
                        <td><button class="btn" onclick="editUser(${u.UserID}, '${(u.Username || '').replace(/'/g, "\\'")}', '${u.Role}', '${u.Status}', '${(u.PhoneNumber || '').replace(/'/g, "\\'")}', ${u.GroupID || 'null'})" style="padding: 5px 10px; font-size: 12px;">Редактировать</button></td>
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
            const companyCheckboxes = document.querySelectorAll('#newUserCompaniesCheckboxes input[name=new_user_company_cb]:checked');
            const companyIds = Array.from(companyCheckboxes).map(cb => cb.value);

            if (!username) {
                showStatus('Введите имя пользователя', 'error');
                return;
            }
            if (role !== 'recipient' && !password) {
                showStatus('Для ролей Пользователь и Администратор требуется пароль', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'add_user');
            formData.append('username', username);
            formData.append('password', password);
            formData.append('role', role);
            formData.append('phoneNumber', phoneNumber);
            companyIds.forEach(cid => formData.append('company_ids[]', cid));

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
        function editUser(id, username, role, status, phoneNumber = '', groupId = null) {
            const u = users.find(x => x.UserID == id);
            const companyIds = (u && u.CompanyIDs) ? u.CompanyIDs : [];
            document.getElementById('editUserId').value = id;
            document.getElementById('editUsername').value = username;
            document.getElementById('editRole').value = role;
            document.getElementById('editStatus').value = status;
            document.getElementById('editPhoneNumber').value = phoneNumber || '';
            document.getElementById('editPassword').value = '';
            const editGroupEl = document.getElementById('editGroupId');
            if (editGroupEl) editGroupEl.value = (groupId !== null && groupId !== undefined && groupId !== '') ? groupId : '';
            const box = document.getElementById('editUserCompaniesCheckboxes');
            box.innerHTML = '';
            (companiesList || []).forEach(c => {
                const checked = companyIds.indexOf(Number(c.CompanyID)) !== -1 ? ' checked' : '';
                box.innerHTML += `<label style="display:block;margin:8px 0;"><input type="checkbox" name="edit_user_company_cb" value="${c.CompanyID}"${checked}> ${escapeHtml(c.CompanyName)}</label>`;
            });
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
            const companyCheckboxes = document.querySelectorAll('#editUserCompaniesCheckboxes input[name=edit_user_company_cb]:checked');
            const companyIds = Array.from(companyCheckboxes).map(cb => cb.value);

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
            const groupIdEl = document.getElementById('editGroupId');
            if (groupIdEl && groupIdEl.value) formData.append('group_id', groupIdEl.value);
            companyIds.forEach(cid => formData.append('company_ids[]', cid));

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

            let table = '<table class="data-table"><thead><tr><th>Текст</th><th>Отправитель</th><th>Получатель</th><th>Группа</th><th>Дата</th></tr></thead><tbody>';
            messages.forEach(item => {
                const groupName = item.GroupName || '—';
                const senderName = item.SenderName || '—';
                table += `
                    <tr>
                        <td>${item.MessageText}</td>
                        <td>${senderName}</td>
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
                loadCheckboxTemplates();
            }
            if (tabName === 'companies') {
                loadCompanies();
            }
            if (tabName === 'user_messages') {
                loadUserMessageTemplates();
            }
            if (tabName === 'sent_received') {
                loadUserMessages();
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

        // Предприятия
        function loadCompanies() {
            const container = document.getElementById('companiesTable');
            if (!container) return;
            container.innerHTML = '<div class="loading">Загрузка...</div>';
            fetch('admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_companies'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    renderCompaniesTable(data.data || []);
                } else {
                    container.innerHTML = '<p class="status-message status-error">Ошибка загрузки</p>';
                }
            })
            .catch(() => {
                container.innerHTML = '<p class="status-message status-error">Ошибка загрузки</p>';
            });
        }

        let companiesData = [];
        function renderCompaniesTable(companies) {
            companiesData = companies || [];
            const container = document.getElementById('companiesTable');
            if (!container) return;
            if (!companies.length) {
                container.innerHTML = '<p style="color:#666">Нет предприятий. Добавьте первое.</p>';
                return;
            }
            let table = '<table class="data-table"><thead><tr><th>ID</th><th>Название</th><th>Действия</th></tr></thead><tbody>';
            companies.forEach(c => {
                table += `<tr><td>${c.CompanyID}</td><td>${escapeHtml(c.CompanyName)}</td><td><button type="button" class="btn btn-secondary" style="padding:5px 10px; font-size:12px;" onclick="openEditCompanyModal(${c.CompanyID})">Редактировать</button></td></tr>`;
            });
            table += '</tbody></table>';
            container.innerHTML = table;
        }

        function openEditCompanyModal(id) {
            const c = companiesData.find(x => x.CompanyID == id);
            document.getElementById('editCompanyId').value = id;
            document.getElementById('editCompanyName').value = c ? (c.CompanyName || '') : '';
            document.getElementById('editCompanyModal').style.display = 'block';
        }

        function closeEditCompanyModal() {
            document.getElementById('editCompanyModal').style.display = 'none';
        }

        function saveCompanyEdit() {
            const id = document.getElementById('editCompanyId').value;
            const name = document.getElementById('editCompanyName').value.trim();
            if (!name) { showCompanyStatus('Введите название', 'error'); return; }
            const fd = new FormData();
            fd.append('action', 'update_company');
            fd.append('company_id', id);
            fd.append('company_name', name);
            fetch('admin.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                showCompanyStatus(data.message, data.success ? 'success' : 'error');
                if (data.success) { closeEditCompanyModal(); loadCompanies(); if (companiesList) loadUsers(); }
            })
            .catch(() => showCompanyStatus('Ошибка сети', 'error'));
        }

        function addCompanySubmit() {
            const name = (document.getElementById('newCompanyName') && document.getElementById('newCompanyName').value) ? document.getElementById('newCompanyName').value.trim() : '';
            if (!name) {
                showCompanyStatus('Введите название предприятия', 'error');
                return;
            }
            const fd = new FormData();
            fd.append('action', 'add_company');
            fd.append('company_name', name);
            fetch('admin.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                showCompanyStatus(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    document.getElementById('newCompanyName').value = '';
                    loadCompanies();
                    if (companiesList) loadUsers();
                }
            })
            .catch(() => showCompanyStatus('Ошибка сети', 'error'));
        }

        function showCompanyStatus(msg, type) {
            const el = document.getElementById('companyStatusMessage');
            if (!el) return;
            el.textContent = msg;
            el.className = 'status-message status-' + (type || 'success');
            el.style.display = 'block';
            setTimeout(() => { el.style.display = 'none'; }, 5000);
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
            const filterEl = document.getElementById('adminMessageFilter');
            const filterVal = filterEl ? filterEl.value : 'all';
            fetch('admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_user_messages&filter=' + encodeURIComponent(filterVal)
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
            const companyVal = (document.getElementById('adminFilterCompany') && document.getElementById('adminFilterCompany').value) ? document.getElementById('adminFilterCompany').value : '';

            const filtered = recipients.filter(r => {
                const name = (r.FullName || '').toLowerCase();
                const phone = (r.PhoneNumber || '').toLowerCase();
                const group = (r.GroupName || '').toLowerCase();
                const matchText = !searchVal || name.includes(searchVal) || phone.includes(searchVal);
                const matchGroup = !groupVal || group === groupVal;
                let matchCompany = true;
                if (companyVal) {
                    const u = (users || []).find(u => String(u.Username || '') === String(r.FullName || ''));
                    const ids = (u && u.CompanyIDs) ? (Array.isArray(u.CompanyIDs) ? u.CompanyIDs : []) : [];
                    matchCompany = ids.indexOf(Number(companyVal)) !== -1;
                }
                return matchText && matchGroup && matchCompany;
            });

            if (filtered.length === 0) {
                list.innerHTML = '<p style="color:#6b7280;">Нет совпадений по фильтру.</p>';
                adminUpdateSelectedCount();
                return;
            }

            list.innerHTML = filtered.map(r => {
                const u = (users || []).find(u => String(u.Username || '') === String(r.FullName || ''));
                const companyNames = (u && u.CompanyNames) ? escapeHtml(u.CompanyNames) : '';
                return `
                <label class="recipient-card">
                    <input type="checkbox" value="${r.RecipientID}">
                    <div class="recipient-meta">
                        <div class="recipient-name">${escapeHtml(r.FullName || '')}</div>
                        <div class="recipient-phone">${escapeHtml(r.PhoneNumber || '')}</div>
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;">
                        ${r.GroupName ? `<span class="badge badge-muted">${escapeHtml(r.GroupName)}</span>` : ''}
                        ${companyNames ? `<span class="badge" style="background:#e0f2fe;color:#0369a1;">${companyNames}</span>` : ''}
                    </div>
                </label>
            `;
            }).join('');
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
        document.getElementById('adminFilterCompany')?.addEventListener('change', renderAdminRecipientsList);
        document.getElementById('adminSelectAllBtn')?.addEventListener('click', adminSelectAllVisible);
        document.getElementById('adminClearSelectionBtn')?.addEventListener('click', adminClearSelection);
        document.getElementById('adminRecipientsList')?.addEventListener('change', adminUpdateSelectedCount);
        document.getElementById('messagesRange')?.addEventListener('change', applyMessagesFilter);
        document.getElementById('logRangeSelect')?.addEventListener('change', applySystemLogFilter);

        function displayUserMessages(data) {
    // Отображение отправленных сообщений
    const sentContainer = document.getElementById('sentMessagesList');
    if (sentContainer) {
        if (data.sent && data.sent.length > 0) {
            sentContainer.innerHTML = data.sent.map(msg => {
                const dataAttr = escapeHtml(JSON.stringify(msg)).replace(/'/g, '&#39;');
                return `
            <div class="message-item sent-message" data-msg='${dataAttr}' onclick="openMessageModal(JSON.parse(this.getAttribute('data-msg')))">
                <div class="message-header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                    <div class="message-text" style="flex:1;">
                        <div class="message-type-badge">📤 Отправлено</div>
                        ${escapeHtml(msg.Text || '')}
                    </div>
                    <button type="button" class="btn btn-danger" style="padding:4px 8px;font-size:12px;flex-shrink:0;" onclick="event.stopPropagation();deleteUserMessageById(${msg.UserMessageID})" title="Удалить">🗑 Удалить</button>
                </div>
                <div class="message-details">
                    <div class="detail-row">
                        <span class="detail-label">📅 Дата:</span>
                        <span class="detail-value">${new Date(msg.SentDate).toLocaleString('ru-RU')}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">👤 Получатель:</span>
                        <span class="detail-value">${escapeHtml(msg.ContactName || '')} 
                            ${msg.GroupName ? `<span class="group-badge">${escapeHtml(msg.GroupName)}</span>` : ''}
                        </span>
                    </div>
                </div>
            </div>
        `;
            }).join('');
        } else {
            sentContainer.innerHTML = '<p style="text-align: center; color: #666;">Нет отправленных сообщений</p>';
        }
    }
    // Отображение полученных сообщений
    const receivedContainer = document.getElementById('receivedMessagesList');
    if (receivedContainer) {
        if (data.received && data.received.length > 0) {
            receivedContainer.innerHTML = data.received.map(msg => {
                const dataAttr = escapeHtml(JSON.stringify(msg)).replace(/'/g, '&#39;');
                return `
            <div class="message-item received-message" data-msg='${dataAttr}' onclick="openMessageModal(JSON.parse(this.getAttribute('data-msg')))">
                <div class="message-header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                    <div class="message-text" style="flex:1;">
                        <div class="message-type-badge">📥 Получено</div>
                        ${escapeHtml(msg.Text || '')}
                    </div>
                    <button type="button" class="btn btn-danger" style="padding:4px 8px;font-size:12px;flex-shrink:0;" onclick="event.stopPropagation();deleteUserMessageById(${msg.UserMessageID})" title="Удалить">🗑 Удалить</button>
                </div>
                <div class="message-details">
                    <div class="detail-row">
                        <span class="detail-label">📅 Дата:</span>
                        <span class="detail-value">${new Date(msg.SentDate).toLocaleString('ru-RU')}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">👤 Отправитель:</span>
                        <span class="detail-value">${escapeHtml(msg.SenderName || msg.ContactName || '')} 
                            ${msg.GroupName ? `<span class="group-badge">${escapeHtml(msg.GroupName)}</span>` : ''}
                        </span>
                    </div>
                </div>
            </div>
        `;
            }).join('');
        } else {
            receivedContainer.innerHTML = '<p style="text-align: center; color: #666;">Нет полученных сообщений</p>';
        }
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
            
            // Показываем модальное окно
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
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

        // Очистка истории сообщений (и личных сообщений — одна общая очистка)
        function clearMessageHistory() {
            if (!confirm('Очистить историю сообщений и все личные сообщения? Это действие нельзя отменить.')) return;
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
                    loadUserMessages();
                }
            })
            .catch(err => showStatus('Ошибка очистки истории: ' + (err.message || err), 'error'));
        }

        // Очистка личных сообщений только для текущего пользователя
        function clearPersonalMessagesHistory() {
            if (!confirm('Очистить личные сообщения только для текущего пользователя?')) return;
            fetch('admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=clear_personal_messages'
            })
            .then(r => r.json())
            .then(data => {
                showStatus(data.message || 'Личные сообщения очищены', data.success ? 'success' : 'error');
                if (data.success) {
                    loadUserMessages();
                }
            })
            .catch(err => showStatus('Ошибка очистки личных сообщений: ' + (err.message || err), 'error'));
        }

        // Удаление одного личного сообщения
        function deleteUserMessageById(userMessageId) {
            if (!confirm('Удалить это сообщение?')) return;
            const formData = new FormData();
            formData.append('action', 'delete_user_message');
            formData.append('user_message_id', userMessageId);
            fetch('admin.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    showStatus(data.message, data.success ? 'success' : 'error');
                    if (data.success) loadUserMessages();
                })
                .catch(err => showStatus('Ошибка удаления: ' + (err.message || err), 'error'));
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
                    if (data.companies && data.companies.length) {
                        companiesList = data.companies;
                    }
                    fillTemplateCompaniesCheckboxes();
                    displaySmsTemplatesTable();
                }
            })
            .catch(error => {
                console.error('Ошибка загрузки шаблонов:', error);
            });
        }

        function fillTemplateCompaniesCheckboxes() {
            const box = document.getElementById('templateCompaniesCheckboxes');
            if (!box) return;
            box.innerHTML = '';
            (companiesList || []).forEach(c => {
                box.innerHTML += `<label style="display:block;margin:8px 0;"><input type="checkbox" name="template_company_cb" value="${c.CompanyID}"> ${escapeHtml(c.CompanyName)}</label>`;
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
            
            let table = '<table class="data-table"><thead><tr><th>Предприятие</th><th>Название</th><th>Текст</th><th>Создан</th><th>Обновлен</th><th>Создал/Обновил</th><th>Действия</th></tr></thead><tbody>';
            
            smsTemplates.forEach(template => {
                const textPreview = (template.TemplateText || '').length > 50 ? 
                    template.TemplateText.substring(0, 50) + '...' : 
                    (template.TemplateText || '');
                const createdAt = template.CreatedAt ? new Date(template.CreatedAt).toLocaleString('ru-RU') : '—';
                const updatedAt = template.UpdatedAt ? new Date(template.UpdatedAt).toLocaleString('ru-RU') : '—';
                const creator = template.CreatedByUsername || '—';
                const companyName = escapeHtml(template.CompanyName || '—');
                const companyId = template.CompanyID || '';
                
                table += `
                    <tr>
                        <td>${companyName}</td>
                        <td><strong>${escapeHtml(template.TemplateName)}</strong></td>
                        <td>${escapeHtml(textPreview)}</td>
                        <td>${createdAt}</td>
                        <td>${updatedAt}</td>
                        <td>${escapeHtml(creator)}</td>
                        <td>
                            <button class="btn" onclick="editSmsTemplate(${template.TemplateID}, ${companyId})" style="padding: 5px 10px; font-size: 12px; margin-right: 5px;">
                                <i class="fas fa-edit"></i> Редактировать
                            </button>
                            <button class="btn btn-danger" onclick="deleteSmsTemplate(${template.TemplateID}, ${companyId})" style="padding: 5px 10px; font-size: 12px;">
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
                formData.append('company_id', editingTemplateCompanyId || '');
            } else {
                const companyCheckboxes = document.querySelectorAll('#templateCompaniesCheckboxes input[name=template_company_cb]:checked');
                const companyIds = Array.from(companyCheckboxes).map(cb => cb.value);
                if (!companyIds.length) {
                    showTemplateStatus('Выберите хотя бы одно предприятие для сохранения шаблона', 'error');
                    return;
                }
                companyIds.forEach(cid => formData.append('company_ids[]', cid));
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

        function editSmsTemplate(id, companyId) {
            const body = 'action=get_sms_template&id=' + id + (companyId ? '&company_id=' + companyId : '');
            fetch('admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data) {
                    const template = data.data;
                    document.getElementById('templateName').value = template.TemplateName;
                    document.getElementById('templateText').value = template.TemplateText;
                    editingTemplateId = template.TemplateID;
                    editingTemplateCompanyId = template.CompanyID || companyId;
                    document.getElementById('templateCompaniesBlock').style.display = 'none';
                    document.getElementById('saveTemplateBtn').innerHTML = '<i class="fas fa-save"></i> Обновить шаблон';
                    document.getElementById('clearTemplateBtn').style.display = 'inline-block';
                    document.getElementById('templateName').scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else {
                    showTemplateStatus('Шаблон не найден', 'error');
                }
            })
            .catch(error => {
                showTemplateStatus('Ошибка при загрузке шаблона: ' + error.message, 'error');
            });
        }

        function deleteSmsTemplate(id, companyId) {
            const template = smsTemplates.find(t => t.TemplateID === id && (t.CompanyID == companyId || !companyId));
            const templateName = template ? template.TemplateName : 'шаблон';
            
            if (!confirm(`Вы уверены, что хотите удалить шаблон "${templateName}"?`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete_sms_template');
            formData.append('id', id);
            if (companyId) formData.append('company_id', companyId);
            
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
            editingTemplateCompanyId = null;
            document.getElementById('saveTemplateBtn').innerHTML = '<i class="fas fa-save"></i> Сохранить шаблон';
            document.getElementById('clearTemplateBtn').style.display = 'none';
            document.getElementById('templateCharCounter').textContent = '0 символов';
            document.getElementById('templateCompaniesBlock').style.display = 'block';
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

        // Чекбокс-шаблоны
        let checkboxTemplates = [];
        let editingCheckboxTemplateId = null;
        let editingCheckboxTemplateCompanyId = null;

        function loadCheckboxTemplates() {
            const companyFilter = document.getElementById('checkboxTemplateCompanyFilter')?.value || '';
            const body = 'action=get_checkbox_templates' + (companyFilter ? '&company_filter=' + encodeURIComponent(companyFilter) : '');
            fetch('admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    checkboxTemplates = data.data || [];
                    if (data.companies && data.companies.length) {
                        companiesList = data.companies;
                        const filterEl = document.getElementById('checkboxTemplateCompanyFilter');
                        if (filterEl && filterEl.options.length <= 1) {
                            filterEl.innerHTML = '<option value="">Все предприятия</option>' + data.companies.map(c => `<option value="${c.CompanyID}">${escapeHtml(c.CompanyName)}</option>`).join('');
                        }
                    }
                    fillCheckboxTemplateCompaniesCheckboxes();
                    displayCheckboxTemplatesTable();
                }
            })
            .catch(err => console.error('Ошибка загрузки чекбокс-шаблонов:', err));
        }

        function fillCheckboxTemplateCompaniesCheckboxes() {
            const box = document.getElementById('checkboxTemplateCompaniesCheckboxes');
            if (!box) return;
            const list = companiesList || [];
            box.innerHTML = list.map(c => `<label style="display:block;margin:8px 0;"><input type="checkbox" name="checkbox_template_company_cb" value="${c.CompanyID}"> ${escapeHtml(c.CompanyName)}</label>`).join('');
        }

        function buildCheckboxTemplateDataFromTable() {
            const table = document.getElementById('checkboxTemplateTable');
            if (!table) return null;
            const thead = table.querySelector('thead tr');
            const tbody = table.querySelector('tbody');
            if (!thead || !tbody) return null;
            const headerCells = Array.from(thead.querySelectorAll('th')).slice(0, -1);
            const columns = headerCells.map(th => {
                const ed = th.querySelector('.editable-cell');
                return ((ed || th).textContent || '').trim();
            }).filter(Boolean);
            if (columns.length < 2) return null;
            const rows = [];
            tbody.querySelectorAll('tr').forEach(tr => {
                const cells = tr.querySelectorAll('td');
                const label = (cells[0]?.textContent || '').trim();
                const values = [];
                for (let i = 1; i < columns.length; i++) {
                    values.push((cells[i]?.textContent || '').trim());
                }
                if (label || values.some(v => v)) rows.push({ label, values });
            });
            return { columns, rows };
        }

        function createCheckboxTemplateHeaderTh(col, colIdx, totalCols) {
            const th = document.createElement('th');
            th.className = colIdx >= 1 ? 'checkbox-template-col-header' : '';
            if (colIdx === 0) {
                th.contentEditable = 'true';
                th.className = 'editable-cell';
                th.dataset.row = '0';
                th.dataset.col = '0';
                th.textContent = col;
            } else {
                const span = document.createElement('span');
                span.contentEditable = 'true';
                span.className = 'editable-cell';
                span.dataset.row = '0';
                span.dataset.col = String(colIdx);
                span.textContent = col;
                th.appendChild(span);
                if (totalCols > 2) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn btn-danger';
                    btn.style.cssText = 'padding: 2px 6px; font-size: 11px; margin-left: 4px;';
                    btn.title = 'Удалить столбец';
                    btn.textContent = '×';
                    btn.onclick = function() {
                        const theadRow = document.querySelector('#checkboxTemplateTable thead tr');
                        const idx = Array.from(theadRow.querySelectorAll('th')).indexOf(th);
                        removeCheckboxTemplateColumn(idx);
                    };
                    th.appendChild(btn);
                }
            }
            return th;
        }

        function populateCheckboxTemplateTable(data) {
            const table = document.getElementById('checkboxTemplateTable');
            if (!table || !data || !data.columns || !data.rows) return;
            const thead = table.querySelector('thead tr');
            const tbody = table.querySelector('tbody');
            thead.innerHTML = '';
            data.columns.forEach((col, i) => {
                thead.appendChild(createCheckboxTemplateHeaderTh(col, i, data.columns.length));
            });
            const delTh = document.createElement('th');
            delTh.style.width = '40px';
            thead.appendChild(delTh);
            tbody.innerHTML = '';
            data.rows.forEach((row, ri) => {
                const tr = document.createElement('tr');
                const labelTd = document.createElement('td');
                labelTd.contentEditable = 'true';
                labelTd.className = 'editable-cell';
                labelTd.dataset.row = String(ri + 1);
                labelTd.dataset.col = '0';
                labelTd.textContent = row.label || '';
                tr.appendChild(labelTd);
                (row.values || []).forEach((val, vi) => {
                    const td = document.createElement('td');
                    td.contentEditable = 'true';
                    td.className = 'editable-cell';
                    td.dataset.row = String(ri + 1);
                    td.dataset.col = String(vi + 1);
                    td.textContent = val;
                    tr.appendChild(td);
                });
                while (tr.children.length < data.columns.length) {
                    const empty = document.createElement('td');
                    empty.contentEditable = 'true';
                    empty.className = 'editable-cell';
                    tr.appendChild(empty);
                }
                const delTd = document.createElement('td');
                delTd.innerHTML = '<button type="button" class="btn btn-danger" style="padding: 2px 8px; font-size: 12px;" onclick="removeCheckboxTemplateRow(this)">&times;</button>';
                tr.appendChild(delTd);
                tbody.appendChild(tr);
            });
        }

        function addCheckboxTemplateRow() {
            const table = document.getElementById('checkboxTemplateTable');
            const tbody = table?.querySelector('tbody');
            if (!tbody) return;
            const headerCells = table.querySelector('thead tr').querySelectorAll('th');
            const colCount = Math.max(1, headerCells.length - 1);
            const tr = document.createElement('tr');
            for (let i = 0; i < colCount; i++) {
                const td = document.createElement('td');
                td.contentEditable = 'true';
                td.className = 'editable-cell';
                td.dataset.row = String(tbody.children.length + 1);
                td.dataset.col = String(i);
                tr.appendChild(td);
            }
            const delTd = document.createElement('td');
            delTd.innerHTML = '<button type="button" class="btn btn-danger" style="padding: 2px 8px; font-size: 12px;" onclick="removeCheckboxTemplateRow(this)">&times;</button>';
            tr.appendChild(delTd);
            tbody.appendChild(tr);
        }

        function addCheckboxTemplateColumn() {
            const table = document.getElementById('checkboxTemplateTable');
            if (!table) return;
            const theadRow = table.querySelector('thead tr');
            const tbody = table.querySelector('tbody');
            const newColIdx = theadRow.querySelectorAll('th').length - 1;
            const th = createCheckboxTemplateHeaderTh('', newColIdx, newColIdx + 1);
            if (newColIdx >= 1) {
                const span = th.querySelector('.editable-cell');
                if (span) span.placeholder = 'Название столбца';
            }
            theadRow.insertBefore(th, theadRow.lastElementChild);
            tbody.querySelectorAll('tr').forEach((tr, ri) => {
                const td = document.createElement('td');
                td.contentEditable = 'true';
                td.className = 'editable-cell';
                td.dataset.row = String(ri + 1);
                td.dataset.col = String(newColIdx);
                tr.insertBefore(td, tr.lastElementChild);
            });
            updateCheckboxTemplateColumnRemoveButtons();
        }

        function removeCheckboxTemplateColumn(colIdx) {
            const table = document.getElementById('checkboxTemplateTable');
            if (!table) return;
            const theadRow = table.querySelector('thead tr');
            const tbody = table.querySelector('tbody');
            const headers = Array.from(theadRow.querySelectorAll('th'));
            if (colIdx <= 0 || colIdx >= headers.length - 1) return;
            const contentColCount = headers.length - 1;
            if (contentColCount <= 2) return;
            headers[colIdx].remove();
            tbody.querySelectorAll('tr').forEach(tr => {
                const cells = tr.querySelectorAll('td');
                if (cells[colIdx]) cells[colIdx].remove();
            });
            updateCheckboxTemplateColumnRemoveButtons();
        }

        function updateCheckboxTemplateColumnRemoveButtons() {
            const theadRow = document.querySelector('#checkboxTemplateTable thead tr');
            if (!theadRow) return;
            const headers = Array.from(theadRow.querySelectorAll('th'));
            const contentColCount = headers.length - 1;
            headers.forEach((th, i) => {
                if (i <= 0 || i >= headers.length - 1) return;
                const existingBtn = th.querySelector('button[title="Удалить столбец"]');
                if (contentColCount <= 2 && existingBtn) existingBtn.remove();
                else if (contentColCount > 2 && !existingBtn) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn btn-danger';
                    btn.style.cssText = 'padding: 2px 6px; font-size: 11px; margin-left: 4px;';
                    btn.title = 'Удалить столбец';
                    btn.textContent = '×';
                    btn.onclick = function() {
                        const header = this.closest('th');
                        const currentIdx = Array.from(theadRow.querySelectorAll('th')).indexOf(header);
                        removeCheckboxTemplateColumn(currentIdx);
                    };
                    th.appendChild(btn);
                }
            });
        }

        function removeCheckboxTemplateRow(btn) {
            const tr = btn?.closest('tr');
            if (tr && tr.parentElement?.querySelectorAll('tr').length > 1) tr.remove();
        }

        function saveCheckboxTemplate() {
            const name = document.getElementById('checkboxTemplateName')?.value?.trim();
            const data = buildCheckboxTemplateDataFromTable();
            if (!name) {
                showCheckboxTemplateStatus('Введите название чекбокс-шаблона', 'error');
                return;
            }
            if (!data || data.columns.length < 2) {
                showCheckboxTemplateStatus('Заполните таблицу: минимум 2 столбца (название + данные)', 'error');
                return;
            }
            const formData = new FormData();
            formData.append('action', 'save_checkbox_template');
            formData.append('template_name', name);
            formData.append('template_data', JSON.stringify(data));
            const cbs = document.querySelectorAll('#checkboxTemplateCompaniesCheckboxes input[name=checkbox_template_company_cb]:checked');
            const ids = Array.from(cbs).map(c => c.value).filter(Boolean);
            if (!ids.length) {
                showCheckboxTemplateStatus('Выберите хотя бы одно предприятие', 'error');
                return;
            }
            ids.forEach(id => formData.append('company_ids[]', id));
            if (editingCheckboxTemplateId) {
                formData.append('checkbox_template_id', editingCheckboxTemplateId);
            }
            fetch('admin.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                showCheckboxTemplateStatus(res.message, res.success ? 'success' : 'error');
                if (res.success) {
                    clearCheckboxTemplateForm();
                    loadCheckboxTemplates();
                }
            })
            .catch(err => showCheckboxTemplateStatus('Ошибка: ' + (err.message || err), 'error'));
        }

        function editCheckboxTemplate(id, companyId) {
            const body = 'action=get_checkbox_template&id=' + id + (companyId ? '&company_id=' + companyId : '');
            fetch('admin.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.data) {
                    const t = data.data;
                    document.getElementById('checkboxTemplateName').value = t.TemplateName || '';
                    let parsed = { columns: ['Название'], rows: [] };
                    try {
                        parsed = JSON.parse(t.TemplateData || '{}');
                    } catch (e) {}
                    if (parsed.columns && parsed.rows) {
                        populateCheckboxTemplateTable(parsed);
                    }
                    editingCheckboxTemplateId = t.CheckboxTemplateID;
                    editingCheckboxTemplateCompanyId = t.CompanyID;
                    document.getElementById('checkboxTemplateCompaniesBlock').style.display = 'block';
                    document.querySelectorAll('#checkboxTemplateCompaniesCheckboxes input[name=checkbox_template_company_cb]').forEach(function(cb) {
                        cb.checked = (cb.value == t.CompanyID);
                    });
                    document.getElementById('saveCheckboxTemplateBtn').innerHTML = '<i class="fas fa-save"></i> Обновить';
                    document.getElementById('clearCheckboxTemplateBtn').style.display = 'inline-block';
                } else {
                    showCheckboxTemplateStatus('Шаблон не найден', 'error');
                }
            })
            .catch(err => showCheckboxTemplateStatus('Ошибка загрузки', 'error'));
        }

        function deleteCheckboxTemplate(id, companyId) {
            const t = checkboxTemplates.find(x => x.CheckboxTemplateID == id && x.CompanyID == companyId);
            if (!confirm('Удалить чекбокс-шаблон "' + (t?.TemplateName || '') + '"?')) return;
            const formData = new FormData();
            formData.append('action', 'delete_checkbox_template');
            formData.append('id', id);
            formData.append('company_id', companyId);
            fetch('admin.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                showCheckboxTemplateStatus(res.message, res.success ? 'success' : 'error');
                if (res.success) {
                    clearCheckboxTemplateForm();
                    loadCheckboxTemplates();
                }
            })
            .catch(err => showCheckboxTemplateStatus('Ошибка удаления', 'error'));
        }

        function clearCheckboxTemplateForm() {
            document.getElementById('checkboxTemplateName').value = '';
            editingCheckboxTemplateId = null;
            editingCheckboxTemplateCompanyId = null;
            document.getElementById('checkboxTemplateCompaniesBlock').style.display = 'block';
            document.querySelectorAll('#checkboxTemplateCompaniesCheckboxes input[name=checkbox_template_company_cb]').forEach(function(cb) { cb.checked = false; });
            document.getElementById('saveCheckboxTemplateBtn').innerHTML = '<i class="fas fa-save"></i> Сохранить чекбокс-шаблон';
            document.getElementById('clearCheckboxTemplateBtn').style.display = 'none';
            populateCheckboxTemplateTable({
                columns: ['Лава', 'ad', 'wrt'],
                rows: [
                    { label: 'Плановые показатели качества', values: ['37,8', '7,5'] },
                    { label: 'Фактические показатели:', values: ['37,8', '6,6'] }
                ]
            });
        }

        function showCheckboxTemplateStatus(msg, type) {
            const el = document.getElementById('checkboxTemplateStatusMessage');
            if (el) {
                el.textContent = msg;
                el.className = 'status-message status-' + (type || 'info');
                el.style.display = 'block';
                setTimeout(() => { el.style.display = 'none'; }, 5000);
            }
        }

        function displayCheckboxTemplatesTable() {
            const container = document.getElementById('checkboxTemplatesTable');
            if (!container) return;
            if (!checkboxTemplates.length) {
                container.innerHTML = '<p style="color:#666">Нет чекбокс-шаблонов</p>';
                return;
            }
            let html = '<table class="data-table"><thead><tr><th>Предприятие</th><th>Название</th><th>Дата создания</th><th>Дата изменения</th><th>Создал/Обновил</th><th>Действия</th></tr></thead><tbody>';
            checkboxTemplates.forEach(t => {
                const created = t.CreatedAt ? (new Date(t.CreatedAt)).toLocaleString('ru-RU') : '—';
                const updated = t.UpdatedAt ? (new Date(t.UpdatedAt)).toLocaleString('ru-RU') : '—';
                const creator = t.CreatedByUsername || t.UpdatedByUsername || '—';
                html += `<tr>
                    <td>${escapeHtml(t.CompanyName || '—')}</td>
                    <td><strong>${escapeHtml(t.TemplateName)}</strong></td>
                    <td>${created}</td>
                    <td>${updated}</td>
                    <td>${escapeHtml(creator)}</td>
                    <td>
                        <button class="btn" onclick="editCheckboxTemplate(${t.CheckboxTemplateID}, ${t.CompanyID})" style="padding: 5px 10px; font-size: 12px; margin-right: 5px;"><i class="fas fa-edit"></i> Редактировать</button>
                        <button class="btn btn-danger" onclick="deleteCheckboxTemplate(${t.CheckboxTemplateID}, ${t.CompanyID})" style="padding: 5px 10px; font-size: 12px;"><i class="fas fa-trash"></i> Удалить</button>
                    </td>
                </tr>`;
            });
            html += '</tbody></table>';
            container.innerHTML = html;
        }
    </script>
</body>
</html>
