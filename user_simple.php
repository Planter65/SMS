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

// Журнал действий
function logSystemAction($category, $action, $details = '') {
    try {
        $conn = connectToDatabase();
        $u = function_exists('getCurrentUser') ? getCurrentUser() : null;
        $performedBy = is_array($u) && isset($u['username']) ? $u['username'] : 'user';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt = $conn->prepare("INSERT INTO system_logs (Category, Action, Details, PerformedBy, IPAddress) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("sssss", $category, $action, $details, $performedBy, $ip);
            $stmt->execute();
            $stmt->close();
        }
        $conn->close();
    } catch (Exception $e) {
        error_log('user logSystemAction exception: ' . $e->getMessage());
    }
}

// Расширение таблицы логов
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
    if (!isset($cols['ProviderStatusText'])) $adds[] = "ADD COLUMN ProviderStatusText TEXT NULL AFTER ProviderSmsGroupId";
    if (!empty($adds)) {
        foreach ($adds as $sqlAdd) {
            @$conn->query("ALTER TABLE messagelogs {$sqlAdd}");
        }
    }
}

// Обработка отправки сообщения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'send_message') {
        $recipient_ids = isset($_POST['recipients']) && is_array($_POST['recipients']) ? $_POST['recipients'] : [];
        $recipient_ids = array_filter(array_map('intval', $recipient_ids));
        $message_text = trim($_POST['message_text'] ?? '');
        
        if (empty($recipient_ids) || $message_text === '') {
            $error = 'Выберите получателя и введите текст сообщения';
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
                
                foreach ($recipient_ids as $recipient_id) {
                    $stmt = $conn->prepare("SELECT r.PhoneNumber, COALESCE(u.Role, '') AS UserRole FROM recipients r LEFT JOIN users u ON r.FullName = u.Username WHERE r.RecipientID = ?");
                    $stmt->bind_param("i", $recipient_id);
                    $stmt->execute();
                    $recipient = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    
                    if (!$recipient || (($recipient['UserRole'] ?? '') === 'admin') || empty($recipient['PhoneNumber'])) {
                        $sent_fail++;
                        continue;
                    }
                    
                    $stmt = $conn->prepare("INSERT INTO messages (Text) VALUES (?)");
                    $stmt->bind_param("s", $message_text);
                    $stmt->execute();
                    $messageId = $conn->insert_id;
                    $stmt->close();
                    
                    $smsResult = sendSms($recipient['PhoneNumber'], $messageWithSender);
                    $smsStatus = (string)($smsResult['status'] ?? ($smsResult['success'] ? 'Отправлено' : 'Ошибка'));
                    $provider = (string)(loadSmsSettings()['SMS_PROVIDER'] ?? '');
                    
                    $stmt = $conn->prepare("INSERT INTO messagelogs (MessageID, RecipientID, Status, Provider, ProviderSmsId, SentDate) VALUES (?, ?, ?, ?, ?, NOW())");
                    $psid = isset($smsResult['sms_id']) ? (string)$smsResult['sms_id'] : null;
                    $stmt->bind_param("iisss", $messageId, $recipient_id, $smsStatus, $provider, $psid);
                    $stmt->execute();
                    $stmt->close();
                    
                    if ($smsResult['success']) $sent_ok++; else $sent_fail++;
                }
                $conn->close();
                
                if ($sent_ok > 0) {
                    $message = "Отправлено SMS: $sent_ok" . (($sent_fail > 0) ? ", ошибок: $sent_fail" : "");
                    logSystemAction('user', 'sms_send', "Отправлено $sent_ok сообщений");
                }
                if ($sent_fail > 0 && $sent_ok == 0) {
                    $error = 'Не удалось отправить SMS';
                }
            } catch (Exception $e) {
                $error = 'Ошибка отправки: ' . $e->getMessage();
            }
        }
    }
}

// Получение данных для формы
$conn = connectToDatabase();
$recipients = [];
$recipientGroups = [];
$smsTemplates = [];

try {
    $current_username = $user['username'] ?? '';
    $selectedCompanyId = getSelectedCompany();
    
    // Получаем получателей
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
            SELECT r.RecipientID, r.FullName, r.PhoneNumber, g.GroupName 
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
            SELECT r.RecipientID, r.FullName, r.PhoneNumber, g.GroupName 
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

    // Группы получателей
    $groupsResult = $conn->query("SELECT DISTINCT GroupName FROM groups WHERE GroupName IS NOT NULL AND GroupName <> '' ORDER BY GroupName");
    if ($groupsResult) {
        while ($gRow = $groupsResult->fetch_assoc()) {
            $recipientGroups[] = $gRow['GroupName'];
        }
    }
    
    // Шаблоны SMS
    $currentUserId = (int)($user['id'] ?? 0);
    $templatesResult = $conn->query("SELECT TemplateID, TemplateName, TemplateText FROM sms_templates WHERE UserID = $currentUserId ORDER BY TemplateName");
    if ($templatesResult) {
        while ($tRow = $templatesResult->fetch_assoc()) {
            $smsTemplates[] = $tRow;
        }
    }
} catch (Exception $e) {
    error_log('Error loading data: ' . $e->getMessage());
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отправка SMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .checkbox-label { padding: 12px; margin: 4px 0; border-radius: 8px; cursor: pointer; transition: background 0.2s; }
        .checkbox-label:hover { background: #f3f4f6; }
        .checkbox-label input[type="checkbox"] { width: 20px; height: 20px; margin-right: 10px; }
        .btn-primary { background: #10b981; color: white; padding: 14px 28px; border: none; border-radius: 12px; font-weight: 600; font-size: 16px; cursor: pointer; width: 100%; }
        .btn-primary:hover { background: #059669; }
        .template-btn { background: #f3f4f6; border: 1px solid #e5e7eb; padding: 10px 16px; border-radius: 8px; cursor: pointer; text-align: left; width: 100%; margin-bottom: 8px; }
        .template-btn:hover { background: #e5e7eb; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <?php include 'navigation.php'; ?>
    
    <div class="container mx-auto px-4 py-8 max-w-2xl">
        <?php if ($message): ?>
            <div class="mb-6 p-4 bg-green-100 text-green-800 rounded-lg text-lg"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="mb-6 p-4 bg-red-100 text-red-800 rounded-lg text-lg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
            <h1 class="text-2xl font-bold text-gray-800 mb-6">📱 Отправить SMS сообщение</h1>
            
            <form method="POST" id="sendSmsForm">
                <input type="hidden" name="action" value="send_message">
                
                <!-- 1. Выбор получателя -->
                <div class="mb-6">
                    <label class="block text-base font-semibold text-gray-700 mb-3">1. Кому отправить?</label>
                    
                    <!-- Фильтр по группе -->
                    <?php if (!empty($recipientGroups)): ?>
                    <div class="mb-4">
                        <label class="block text-sm text-gray-600 mb-2">Фильтр по группе:</label>
                        <select id="groupFilter" class="w-full border border-gray-300 rounded-lg px-4 py-3 text-base" onchange="filterRecipients()">
                            <option value="">Все группы</option>
                            <?php foreach ($recipientGroups as $gName): ?>
                                <option value="<?php echo htmlspecialchars($gName); ?>"><?php echo htmlspecialchars($gName); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Список получателей -->
                    <div class="border border-gray-300 rounded-lg p-4 max-h-64 overflow-y-auto bg-gray-50">
                        <?php if (empty($recipients)): ?>
                            <p class="text-gray-500 text-base">Нет доступных получателей. Обратитесь к администратору.</p>
                        <?php else: ?>
                            <?php foreach ($recipients as $r): ?>
                            <label class="checkbox-label flex items-center" data-group="<?php echo htmlspecialchars($r['GroupName'] ?? ''); ?>">
                                <input type="checkbox" name="recipients[]" value="<?php echo (int)$r['RecipientID']; ?>" class="recipient-checkbox">
                                <span class="text-base text-gray-800"><?php echo htmlspecialchars($r['FullName']); ?></span>
                                <?php if (!empty($r['GroupName'])): ?>
                                    <span class="text-gray-500 text-sm ml-2">(<?php echo htmlspecialchars($r['GroupName']); ?>)</span>
                                <?php endif; ?>
                            </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <p class="text-sm text-gray-500 mt-2">✓ Можно выбрать несколько получателей</p>
                </div>
                
                <!-- 2. Текстовое поле -->
                <div class="mb-6">
                    <label class="block text-base font-semibold text-gray-700 mb-3">2. Текст сообщения</label>
                    <textarea name="message_text" id="message_text" rows="5" maxlength="600" required 
                              class="w-full border border-gray-300 rounded-lg px-4 py-3 text-base focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200" 
                              placeholder="Введите текст вашего сообщения..."></textarea>
                    <div class="flex justify-between mt-2">
                        <span class="text-sm text-gray-500" id="charCounter">0 / 600 символов</span>
                    </div>
                </div>
                
                <!-- 3. Выбор шаблона -->
                <div class="mb-6">
                    <label class="block text-base font-semibold text-gray-700 mb-3">3. Или выберите готовый шаблон</label>
                    <?php if (empty($smsTemplates)): ?>
                        <p class="text-gray-500 text-base">У вас нет сохранённых шаблонов. Введите текст вручную.</p>
                    <?php else: ?>
                        <div class="space-y-2">
                            <?php foreach ($smsTemplates as $t): ?>
                            <button type="button" class="template-btn" onclick="useTemplate(<?php echo htmlspecialchars(json_encode($t['TemplateText']), ENT_QUOTES, 'UTF-8'); ?>)">
                                📄 <strong><?php echo htmlspecialchars($t['TemplateName']); ?></strong>
                                <div class="text-sm text-gray-600 truncate"><?php echo htmlspecialchars(mb_substr($t['TemplateText'], 0, 80)); ?><?php echo mb_strlen($t['TemplateText']) > 80 ? '...' : ''; ?></div>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Кнопка отправки -->
                <button type="submit" class="btn-primary mt-8">
                    📨 Отправить SMS
                </button>
            </form>
        </div>
    </div>
    
    <script>
        // Счётчик символов
        document.getElementById('message_text').addEventListener('input', function() {
            var len = this.value.length;
            document.getElementById('charCounter').textContent = len + ' / 600 символов';
            if (len > 600) {
                document.getElementById('charCounter').classList.add('text-red-600');
            } else {
                document.getElementById('charCounter').classList.remove('text-red-600');
            }
        });
        
        // Использование шаблона
        function useTemplate(text) {
            var textarea = document.getElementById('message_text');
            textarea.value = text;
            var event = new Event('input');
            textarea.dispatchEvent(event);
            textarea.focus();
        }
        
        // Фильтрация получателей по группе
        function filterRecipients() {
            var selectedGroup = document.getElementById('groupFilter').value;
            var checkboxes = document.querySelectorAll('.recipient-checkbox');
            var labels = document.querySelectorAll('.checkbox-label');
            
            labels.forEach(function(label, index) {
                var group = label.getAttribute('data-group') || '';
                if (selectedGroup === '' || group === selectedGroup) {
                    label.style.display = 'flex';
                } else {
                    label.style.display = 'none';
                    checkboxes[index].checked = false;
                }
            });
        }
    </script>
</body>
</html>
