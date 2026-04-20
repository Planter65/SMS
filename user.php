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

// Обработка смены предприятия через GET-параметр
if (isset($_GET['change_company'])) {
    $newCompanyId = (int)$_GET['change_company'];
    if ($newCompanyId > 0) {
        // Проверяем, что предприятие доступно пользователю
        $availableCompanies = function_exists('getAvailableCompanies') ? getAvailableCompanies() : [];
        $companyFound = false;
        foreach ($availableCompanies as $company) {
            if ($company['CompanyID'] == $newCompanyId) {
                $companyFound = true;
                break;
            }
        }
        if ($companyFound) {
            setSelectedCompany($newCompanyId);
            header('Location: user.php');
            exit;
        }
    }
}

// Обработка отправки сообщения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'send_message') {
        $recipient_ids = isset($_POST['recipients']) && is_array($_POST['recipients']) ? $_POST['recipients'] : [];
        $recipient_ids = array_filter(array_map('intval', $recipient_ids));
        $message_text = trim($_POST['message_text'] ?? '');
        $test_mode = isset($_POST['test_mode']) && $_POST['test_mode'] == '1';
        
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
                $test_details = [];
                
                foreach ($recipient_ids as $recipient_id) {
                    $stmt = $conn->prepare("SELECT r.PhoneNumber, r.FullName, COALESCE(u.Role, '') AS UserRole FROM recipients r LEFT JOIN users u ON r.FullName = u.Username WHERE r.RecipientID = ?");
                    $stmt->bind_param("i", $recipient_id);
                    $stmt->execute();
                    $recipient = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    
                    if (!$recipient) {
                        $sent_fail++;
                        $test_details[] = "ID $recipient_id: получатель не найден";
                        continue;
                    }
                    
                    if (($recipient['UserRole'] ?? '') === 'admin') {
                        $sent_fail++;
                        $test_details[] = "{$recipient['FullName']}: пропущен (администратор)";
                        continue;
                    }
                    
                    if (empty($recipient['PhoneNumber'])) {
                        $sent_fail++;
                        $test_details[] = "{$recipient['FullName']}: нет номера телефона";
                        continue;
                    }
                    
                    $test_details[] = "{$recipient['FullName']} ({$recipient['PhoneNumber']}): готов к отправке";
                    
                    // В тестовом режиме не отправляем реально, только проверяем
                    if ($test_mode) {
                        $sent_ok++;
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
                
                if ($test_mode) {
                    // Тестовый режим - возвращаем детали проверки
                    $message = "Проверка завершена. Найдено получателей: " . count($recipient_ids) . ".\\n";
                    $message .= "Готовы к отправке: $sent_ok.\\n";
                    if ($sent_fail > 0) {
                        $message .= "Проблемы: $sent_fail.\\n";
                    }
                    $message .= "\\nДетали:\\n" . implode("\\n", array_slice($test_details, 0, 20));
                    if (count($test_details) > 20) {
                        $message .= "\\n... и ещё " . (count($test_details) - 20) . " записей";
                    }
                    echo json_encode(['success' => true, 'message' => $message]);
                    exit;
                }
                
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
$checkboxTemplates = [];

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
    
    // Чекбокс-шаблоны
    if ($selectedCompanyId) {
        $companyId = (int)$selectedCompanyId;
        $checkboxResult = $conn->query("SELECT CheckboxTemplateID, CompanyID, TemplateName, TemplateData FROM sms_checkbox_templates WHERE CompanyID = $companyId ORDER BY TemplateName");
        if ($checkboxResult) {
            while ($cbRow = $checkboxResult->fetch_assoc()) {
                $checkboxTemplates[] = $cbRow;
            }
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
        
        /* Верхняя панель */
        .top-bar {
            background: linear-gradient(135deg, #064e3b, #047857);
            color: white;
            padding: 7px 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            width: 100%;
            box-sizing: border-box;
        }
        .top-bar-container {
            max-width: none;
            margin: 0;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            width: 100%;
        }
        .top-bar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-size: 16px;
        }
        .top-bar-brand-icon {
            font-size: 20px;
        }
        .top-bar-menu {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .top-bar-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            text-decoration: none;
            transition: background 0.2s;
        }
        .top-bar-link:hover {
            background: rgba(255,255,255,0.15);
            text-decoration: none;
        }
        .top-bar-user {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 8px;
            background: rgba(255,255,255,0.1);
            font-weight: 500;
        }
        .top-bar-role {
            font-size: 12px;
            opacity: 0.8;
        }
        /* Выпадающий список предприятий */
        .company-dropdown {
            position: relative;
            display: inline-block;
        }
        .company-dropdown-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 8px;
            background: rgba(255,255,255,0.1);
            color: white;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
            border: none;
        }
        .company-dropdown-btn:hover {
            background: rgba(255,255,255,0.2);
        }
        .company-dropdown-menu {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            min-width: 200px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            margin-top: 4px;
            z-index: 1000;
            overflow: hidden;
        }
        .company-dropdown-menu a {
            display: block;
            padding: 10px 16px;
            color: #333;
            text-decoration: none;
            transition: background 0.2s;
        }
        .company-dropdown-menu a:hover {
            background: #f3f4f6;
        }
        .company-dropdown-menu .current-company {
            background: #e8f4ff;
            font-weight: 600;
        }
        .company-dropdown.active .company-dropdown-menu {
            display: block;
        }
        @media (max-width: 768px) {
            .top-bar-container {
                flex-direction: column;
                align-items: stretch;
            }
            .top-bar-menu {
                justify-content: center;
            }
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen" style="background-image: url('fon.gif'); background-size: cover; background-position: center; background-attachment: fixed;">
    <!-- Верхняя панель - без масштабирования -->
    <?php
    // Получаем доступные предприятия для пользователя
    $availableCompanies = function_exists('getAvailableCompanies') ? getAvailableCompanies() : [];
    $currentCompanyId = getSelectedCompany();
    $currentCompanyName = getSelectedCompanyName();
    ?>
    <!-- Верхняя панель -->
    <div class="top-bar">
        <div class="top-bar-container">
            <div class="top-bar-brand">
                <span class="top-bar-brand-icon">📡</span>
                <span>СМС информирование — <?php echo htmlspecialchars($currentCompanyName ?: 'Шахта им. Рубана'); ?></span>
            </div>
            <div class="top-bar-menu">
                <?php if (!empty($availableCompanies)): ?>
                <div class="company-dropdown" id="companyDropdown">
                    <button class="company-dropdown-btn" onclick="toggleCompanyDropdown()" type="button">
                        <span>&#127970;</span>
                        <span><?php echo htmlspecialchars($currentCompanyName ?: 'Выберите предприятие'); ?></span>
                        <span>&#9662;</span>
                    </button>
                    <div class="company-dropdown-menu">
                        <?php foreach ($availableCompanies as $company): ?>
                            <?php $isCurrent = $company['CompanyID'] == $currentCompanyId; ?>
                            <a href="?change_company=<?php echo $company['CompanyID']; ?>" 
                               class="<?php echo $isCurrent ? 'current-company' : ''; ?>">
                                <?php echo htmlspecialchars($company['CompanyName']); ?>
                                <?php if ($isCurrent): ?>&#10003;<?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="top-bar-user">
                    <span>&#128100;</span>
                    <span><?php echo htmlspecialchars($user['username'] ?? 'Пользователь'); ?></span>
                    <span class="top-bar-role"><?php echo $user['role'] === 'admin' ? 'Администратор' : 'Пользователь'; ?></span>
                </div>
                <a href="logout.php" class="top-bar-link">Выйти</a>
            </div>
        </div>
    </div>
    
    <script>
    function toggleCompanyDropdown() {
        document.getElementById('companyDropdown').classList.toggle('active');
    }
    
    // Закрыть выпадающий список при клике вне его
    document.addEventListener('click', function(event) {
        var dropdown = document.getElementById('companyDropdown');
        if (dropdown && !event.target.closest('.company-dropdown')) {
            dropdown.classList.remove('active');
        }
    });
    </script>
    
    <!-- Контейнер с масштабированием для основного контента -->
    <div style="transform: scale(0.6); transform-origin: top center; width: 100%; display: flex; justify-content: center;">
    <div class="container mx-auto px-4 py-8 max-w-2xl" style="width: 166.67vw; max-width: 1200px;">
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
                    
                    <!-- Кнопки быстрого выбора групп -->
                    <div class="mb-4 space-y-2">
                        <p class="text-sm text-gray-600 font-medium">📋 Быстрый выбор группы:</p>
                        <?php 
                        // Группируем получателей по группам
                        $groupedRecipients = [];
                        foreach ($recipients as $r) {
                            $groupName = $r['GroupName'] ?? 'Без группы';
                            if (!isset($groupedRecipients[$groupName])) {
                                $groupedRecipients[$groupName] = [];
                            }
                            $groupedRecipients[$groupName][] = $r;
                        }
                        foreach ($groupedRecipients as $gName => $members): 
                            $count = count($members);
                        ?>
                        <button type="button" 
                                class="w-full text-left bg-blue-50 hover:bg-blue-100 border border-blue-200 rounded-lg px-4 py-3 text-base transition-colors"
                                onclick="selectGroup('<?php echo htmlspecialchars($gName, ENT_QUOTES, 'UTF-8'); ?>')">
                            📁 <strong><?php echo htmlspecialchars($gName); ?></strong> 
                            <span class="text-blue-600">(<?php echo $count; ?> чел.)</span>
                            <span class="text-gray-500 text-sm ml-2">Нажмите чтобы выбрать всех</span>
                        </button>
                        <?php endforeach; ?>
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
                    <p class="text-sm text-gray-500 mt-2">✓ Можно выбрать несколько получателей или целую группу</p>
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
                    
                    <!-- Обычные текстовые шаблоны -->
                    <?php if (!empty($smsTemplates)): ?>
                        <div class="space-y-2 mb-4">
                            <p class="text-sm text-gray-500 font-medium">📄 Текстовые шаблоны:</p>
                            <?php foreach ($smsTemplates as $t): ?>
                            <button type="button" class="template-btn" onclick="useTemplate(<?php echo htmlspecialchars(json_encode($t['TemplateText']), ENT_QUOTES, 'UTF-8'); ?>)">
                                📄 <strong><?php echo htmlspecialchars($t['TemplateName']); ?></strong>
                                <div class="text-sm text-gray-600 truncate"><?php echo htmlspecialchars(mb_substr($t['TemplateText'], 0, 80)); ?><?php echo mb_strlen($t['TemplateText']) > 80 ? '...' : ''; ?></div>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Чекбокс-шаблоны -->
                    <?php if (!empty($checkboxTemplates)): ?>
                        <div class="space-y-2">
                            <p class="text-sm text-gray-500 font-medium">✅ Чекбокс-шаблоны (таблица):</p>
                            <?php foreach ($checkboxTemplates as $cb): ?>
                            <button type="button" class="template-btn border-emerald-300 bg-emerald-50/30 hover:bg-emerald-100" 
                                    onclick="useCheckboxTemplate(<?php echo htmlspecialchars(json_encode($cb['TemplateData']), ENT_QUOTES, 'UTF-8'); ?>, '<?php echo htmlspecialchars($cb['TemplateName'], ENT_QUOTES, 'UTF-8'); ?>')">
                                ✅ <strong><?php echo htmlspecialchars($cb['TemplateName']); ?></strong>
                                <div class="text-sm text-gray-600">Таблица — выберите нужные строки и столбцы</div>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (empty($smsTemplates) && empty($checkboxTemplates)): ?>
                        <p class="text-gray-500 text-base">У вас нет сохранённых шаблонов. Введите текст вручную.</p>
                    <?php endif; ?>
                </div>
                
                <!-- Кнопки действий -->
                <div class="flex flex-col sm:flex-row gap-4 mt-8">
                    <button type="submit" class="btn-primary flex-1 text-lg py-4">
                        📨 Отправить SMS
                    </button>
                    <button type="button" onclick="testSendSms()" class="bg-orange-500 hover:bg-orange-600 text-white font-bold py-4 px-8 rounded-lg shadow-lg transition duration-200 flex-1 text-lg">
                        🧪 ТЕСТОВАЯ ОТПРАВКА (проверка)
                    </button>
                </div>
            </form>
        </div>
    </div>
    </div> <!-- Конец контейнера с масштабированием -->
    
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
        
        // Использование обычного шаблона
        function useTemplate(text) {
            var textarea = document.getElementById('message_text');
            textarea.value = text;
            var event = new Event('input');
            textarea.dispatchEvent(event);
            textarea.focus();
        }

        // Использование чекбокс-шаблона (таблица)
        function useCheckboxTemplate(templateData, templateName) {
            var parsed;
            try {
                parsed = JSON.parse(templateData);
            } catch (e) {
                alert('Ошибка чтения шаблона');
                return;
            }

            var cols = parsed.columns || [];
            var rows = parsed.rows || [];

            // Создаём модальное окно для выбора строк и столбцов
            var modalHtml = '<div id="checkboxModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">';
            modalHtml += '<div class="bg-white rounded-xl p-6 max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">';
            modalHtml += '<h3 class="text-xl font-bold mb-4">✅ ' + templateName + '</h3>';
            
            // Выбор столбцов (кроме первого - это label)
            if (cols.length > 1) {
                modalHtml += '<p class="text-sm text-gray-600 mb-2">Выберите столбцы для вставки:</p>';
                modalHtml += '<div class="flex flex-wrap gap-2 mb-4">';
                for (var ci = 1; ci < cols.length; ci++) {
                    modalHtml += '<label class="flex items-center gap-2 px-3 py-2 bg-gray-100 rounded-lg cursor-pointer hover:bg-emerald-100">';
                    modalHtml += '<input type="checkbox" class="cb-col-select" data-col="' + ci + '" checked style="width:18px;height:18px;">';
                    modalHtml += '<span>' + escapeHtml(cols[ci]) + '</span></label>';
                }
                modalHtml += '</div>';
            }

            // Выбор строк
            modalHtml += '<p class="text-sm text-gray-600 mb-2">Выберите строки для вставки:</p>';
            modalHtml += '<table class="w-full border-collapse border border-gray-300 mb-4">';
            modalHtml += '<thead><tr class="bg-gray-100"><th class="border border-gray-300 p-2 text-left">✓</th>';
            cols.forEach(function(c) {
                modalHtml += '<th class="border border-gray-300 p-2 text-left">' + escapeHtml(c) + '</th>';
            });
            modalHtml += '</tr></thead><tbody>';

            rows.forEach(function(r, ri) {
                var label = r.label || '';
                var vals = r.values || [];
                modalHtml += '<tr><td class="border border-gray-300 p-2 text-center">';
                modalHtml += '<input type="checkbox" class="cb-row-select" data-row="' + ri + '" style="width:20px;height:20px;">';
                modalHtml += '</td><td class="border border-gray-300 p-2">' + escapeHtml(label) + '</td>';
                vals.forEach(function(v) {
                    modalHtml += '<td class="border border-gray-300 p-2">' + escapeHtml(v) + '</td>';
                });
                modalHtml += '</tr>';
            });
            modalHtml += '</tbody></table>';

            modalHtml += '<div class="flex gap-3">';
            modalHtml += '<button onclick="applyCheckboxTemplate()" class="bg-emerald-600 text-white px-6 py-3 rounded-lg font-semibold hover:bg-emerald-700">Вставить в сообщение</button>';
            modalHtml += '<button onclick="closeCheckboxModal()" class="bg-gray-300 text-gray-800 px-6 py-3 rounded-lg font-semibold hover:bg-gray-400">Отмена</button>';
            modalHtml += '</div></div></div>';

            document.body.insertAdjacentHTML('beforeend', modalHtml);
        }

        function escapeHtml(text) {
            return String(text || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function closeCheckboxModal() {
            var modal = document.getElementById('checkboxModal');
            if (modal) modal.remove();
        }

        function applyCheckboxTemplate() {
            var selectedCols = [];
            document.querySelectorAll('.cb-col-select:checked').forEach(function(cb) {
                selectedCols.push(parseInt(cb.getAttribute('data-col'), 10));
            });

            var selectedRows = [];
            document.querySelectorAll('.cb-row-select:checked').forEach(function(cb) {
                selectedRows.push(parseInt(cb.getAttribute('data-row'), 10));
            });

            if (selectedRows.length === 0) {
                alert('Выберите хотя бы одну строку');
                return;
            }

            // Получаем данные из таблицы (они хранятся в DOM)
            var table = document.querySelector('#checkboxModal table tbody');
            var rows = table.querySelectorAll('tr');
            
            var resultText = '';
            selectedRows.forEach(function(ri) {
                var row = rows[ri];
                var cells = row.querySelectorAll('td');
                if (cells.length > 1) {
                    var labelText = cells[1].textContent.trim();
                    var values = [];
                    for (var i = 0; i < selectedCols.length; i++) {
                        var colIdx = selectedCols[i];
                        if (cells.length > colIdx + 1) {
                            values.push(cells[colIdx + 1].textContent.trim());
                        }
                    }
                    if (values.length > 0) {
                        resultText += labelText + ': ' + values.join(', ') + '; ';
                    }
                }
            });

            // Вставляем в текстовое поле (без дополнительного переноса строки)
            var textarea = document.getElementById('message_text');
            if (textarea.value) {
                textarea.value += ' ' + resultText;
            } else {
                textarea.value = resultText;
            }

            // Триггерим событие input для обновления счётчика
            var event = new Event('input');
            textarea.dispatchEvent(event);
            textarea.focus();

            closeCheckboxModal();
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

        // Выбор всей группы сразу
        function selectGroup(groupName) {
            var checkboxes = document.querySelectorAll('.recipient-checkbox');
            var labels = document.querySelectorAll('.checkbox-label');

            labels.forEach(function(label, index) {
                var group = label.getAttribute('data-group') || '';
                // Сравниваем название группы (для "Без группы" сравниваем с пустым или отсутствующим)
                var isMatch = (groupName === 'Без группы' && group === '') || (group === groupName);
                checkboxes[index].checked = isMatch;
            });

            // Прокручиваем к списку получателей для визуального подтверждения
            document.querySelector('.border-gray-300.max-h-64').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        // ТЕСТОВАЯ ОТПРАВКА - проверка без реального списания
        function testSendSms() {
            var form = document.getElementById('sendSmsForm');
            var formData = new FormData(form);
            
            // Добавляем флаг тестового режима
            formData.append('test_mode', '1');
            
            // Показываем индикатор загрузки
            var btn = event.target;
            var originalText = btn.innerHTML;
            btn.innerHTML = '⏳ Проверка...';
            btn.disabled = true;
            
            fetch('user.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ ТЕСТ УСПЕШЕН!\\n\\n' + data.message + '\\n\\nСМС не были отправлены реально (тестовый режим).\\nТеперь можете нажать \"Отправить SMS\" для реальной рассылки.');
                } else {
                    alert('❌ ОШИБКА ТЕСТА:\\n\\n' + data.message);
                }
            })
            .catch(error => {
                alert('❌ Ошибка соединения: ' + error);
            })
            .finally(() => {
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
        }
    </script>
    </div>
</body>
</html>
