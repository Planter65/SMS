<?php
require_once 'auth.php';

// Проверяем авторизацию
requireAuth();

$user = getCurrentUser();
$message = '';
$error = '';

// Получаем список получателей и групп
$conn = connectToDatabase();
$recipients = [];
$groups = [];
$templates = [];

try {
    // Получаем список получателей
    $result = $conn->query("
        SELECT r.RecipientID, r.FullName, r.PhoneNumber, g.GroupName 
        FROM recipients r 
        LEFT JOIN groups g ON r.GroupID = g.GroupID 
        ORDER BY r.FullName
    ");
    
    while ($row = $result->fetch_assoc()) {
        $recipients[] = $row;
    }
    
    // Получаем список групп
    $result = $conn->query("SELECT GroupID, GroupName FROM groups ORDER BY GroupName");
    
    while ($row = $result->fetch_assoc()) {
        $groups[] = $row;
    }

    // Получаем список шаблонов SMS для выбранного предприятия
    $companyId = getSelectedCompany();
    if ($companyId) {
        // Убеждаемся, что таблица шаблонов существует
        $conn->query("CREATE TABLE IF NOT EXISTS sms_templates (
            TemplateID INT AUTO_INCREMENT PRIMARY KEY,
            CompanyID INT NOT NULL,
            TemplateName VARCHAR(255) NOT NULL,
            TemplateText TEXT NOT NULL,
            CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
            UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (CompanyID) REFERENCES companies(CompanyID) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmt = $conn->prepare("SELECT TemplateID, TemplateName, TemplateText FROM sms_templates WHERE CompanyID = ? ORDER BY TemplateID DESC");
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $templates[] = $row;
        }

        $stmt->close();
    }

} catch (Exception $e) {
    $error = 'Ошибка загрузки данных: ' . $e->getMessage();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отправка SMS - Система СМС информирования</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 20px;
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

        .btn {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 10px 5px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(76, 175, 80, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
        }

        .btn-secondary:hover {
            box-shadow: 0 8px 25px rgba(108, 117, 125, 0.3);
        }

        .recipients-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
        }

        .recipient-item {
            display: flex;
            align-items: center;
            padding: 10px;
            background: white;
            border-radius: 5px;
            margin: 5px 0;
            border: 1px solid #ddd;
        }

        .recipient-item input[type="checkbox"] {
            margin-right: 10px;
            transform: scale(1.2);
        }

        .recipient-info {
            flex: 1;
        }

        .recipient-name {
            font-weight: 600;
            color: #333;
        }

        .recipient-phone {
            color: #666;
            font-size: 14px;
        }

        .recipient-group {
            background: #4CAF50;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            margin-left: 10px;
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

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
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
            .container {
                padding: 20px;
            }
            
            .page-header h1 {
                font-size: 1.8em;
            }
            
            .stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include 'navigation.php'; ?>
    
    <div class="page-header">
        <div class="header-content">
            <h1>📤 Отправка SMS сообщения</h1>
            <p>Отправьте SMS сообщение выбранным получателям</p>
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

        <!-- Статистика -->
        <div class="stats">
            <?php
            try {
                $conn = connectToDatabase();
                
                // Статистика получателей
                $result = $conn->query("SELECT COUNT(*) as count FROM recipients");
                $recipients_count = $result->fetch_assoc()['count'];
                
                // Статистика групп
                $result = $conn->query("SELECT COUNT(*) as count FROM groups");
                $groups_count = $result->fetch_assoc()['count'];
                
                // Статистика сообщений
                $result = $conn->query("SELECT COUNT(*) as count FROM messages");
                $messages_count = $result->fetch_assoc()['count'];
                
                // Статистика отправленных СМС
                $result = $conn->query("SELECT COUNT(*) as count FROM messagelogs");
                $sent_count = $result->fetch_assoc()['count'];
                
                $conn->close();
            } catch (Exception $e) {
                $recipients_count = 0;
                $groups_count = 0;
                $messages_count = 0;
                $sent_count = 0;
            }
            ?>
            
            <div class="stat-card">
                <div class="stat-number"><?php echo $recipients_count; ?></div>
                <div class="stat-label">Получателей</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $groups_count; ?></div>
                <div class="stat-label">Групп</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $messages_count; ?></div>
                <div class="stat-label">Сообщений</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $sent_count; ?></div>
                <div class="stat-label">Отправлено СМС</div>
            </div>
        </div>

        <!-- Форма отправки СМС -->
        <form id="smsForm" method="POST" action="send_sms.php">
            <div class="card">
                <h2>💬 Отправка СМС сообщения</h2>

                <?php if (!empty($templates)): ?>
                <div class="form-group">
                    <label for="templateSelect">Шаблоны сообщения:</label>
                    <select id="templateSelect">
                        <option value="">Выберите шаблон...</option>
                        <?php foreach ($templates as $template): ?>
                            <option 
                                value="<?php echo $template['TemplateID']; ?>" 
                                data-text="<?php echo htmlspecialchars($template['TemplateText'], ENT_QUOTES, 'UTF-8'); ?>"
                            >
                                <?php echo htmlspecialchars($template['TemplateName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: #666; font-size: 12px; margin-top: 5px; display: block;">
                        При выборе шаблона его текст автоматически подставится в поле сообщения. Вы можете отредактировать текст перед отправкой.
                    </small>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="messageText">Текст сообщения:</label>
                    <textarea id="messageText" name="messageText" maxlength="160" placeholder="Введите текст СМС сообщения (максимум 160 символов)" required></textarea>
                    <div class="char-counter" id="charCounter">0 / 160</div>
                </div>

                <div class="form-group">
                    <label for="groupSelect">Выберите группу получателей:</label>
                    <select id="groupSelect" name="groupSelect">
                        <option value="">Выберите группу (опционально)</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?php echo $group['GroupID']; ?>"><?php echo htmlspecialchars($group['GroupName']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" id="sendToAllEmployees" name="sendToAllEmployees" value="1" style="width: auto; transform: scale(1.3);">
                        <span style="font-weight: 600; color: #4CAF50;">📢 Отправить всем сотрудникам (группа "Сотрудники")</span>
                    </label>
                    <small style="color: #666; font-size: 12px; margin-left: 30px; display: block; margin-top: 5px;">
                        При выборе этой опции сообщение будет отправлено всем получателям из группы "Сотрудники"
                    </small>
                </div>

                <div class="recipients-section">
                    <h3>📱 Выберите получателей:</h3>
                    <div id="recipientsList">
                        <?php if (!empty($recipients)): ?>
                            <?php foreach ($recipients as $recipient): ?>
                                <div class="recipient-item">
                                    <input type="checkbox" name="recipients[]" value="<?php echo $recipient['RecipientID']; ?>" id="recipient_<?php echo $recipient['RecipientID']; ?>">
                                    <div class="recipient-info">
                                        <div class="recipient-name"><?php echo htmlspecialchars($recipient['FullName']); ?></div>
                                        <div class="recipient-phone"><?php echo htmlspecialchars($recipient['PhoneNumber']); ?></div>
                                    </div>
                                    <?php if ($recipient['GroupName']): ?>
                                        <span class="recipient-group"><?php echo htmlspecialchars($recipient['GroupName']); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p>Получатели не найдены. Добавьте получателей через панель администратора.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <button type="submit" class="btn">📤 Отправить СМС</button>
                <button type="button" class="btn btn-secondary" onclick="selectAll()">✅ Выбрать всех</button>
                <button type="button" class="btn btn-secondary" onclick="deselectAll()">❌ Снять выбор</button>
            </div>
        </form>

        <!-- Сообщения о статусе -->
        <div id="statusMessage" class="status-message"></div>
    </div>

    <script>
        // Счетчик символов
        const messageText = document.getElementById('messageText');
        const charCounter = document.getElementById('charCounter');
        const templateSelect = document.getElementById('templateSelect');
        const maxLength = 160;

        messageText.addEventListener('input', function() {
            const length = this.value.length;
            charCounter.textContent = length + ' / ' + maxLength;
            
            charCounter.className = 'char-counter';
            if (length > maxLength * 0.8) {
                charCounter.classList.add('warning');
            }
            if (length > maxLength) {
                charCounter.classList.add('error');
            }
        });

        // Подстановка текста шаблона в поле сообщения (добавление к существующему тексту)
        if (templateSelect) {
            templateSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const templateText = selectedOption.getAttribute('data-text') || '';

                if (!templateText) {
                    return;
                }

                // Добавляем текст шаблона к уже существующему тексту
                let current = messageText.value || '';
                if (current.trim().length === 0) {
                    current = templateText;
                } else {
                    // Добавляем с пробелом, чтобы не склеивались слова
                    current = current + ' ' + templateText;
                }
                messageText.value = current;

                // Обновляем счетчик символов
                const event = new Event('input');
                messageText.dispatchEvent(event);
            });
        }

        // Фильтрация получателей по группе
        const groupSelect = document.getElementById('groupSelect');
        const recipientsList = document.getElementById('recipientsList');
        const sendToAllEmployees = document.getElementById('sendToAllEmployees');
        let recipientItems = recipientsList.querySelectorAll('.recipient-item');

        function updateRecipientsDisplay() {
            const selectedGroup = groupSelect.value;
            const sendToAll = sendToAllEmployees.checked;
            
            recipientItems.forEach(item => {
                const groupSpan = item.querySelector('.recipient-group');
                const checkbox = item.querySelector('input[type="checkbox"]');
                
                if (sendToAll) {
                    // При отправке всем сотрудникам показываем только группу "Сотрудники"
                    if (groupSpan && groupSpan.textContent.trim() === 'Сотрудники') {
                        item.style.display = 'flex';
                        checkbox.checked = true;
                    } else {
                        item.style.display = 'none';
                        checkbox.checked = false;
                    }
                } else if (!selectedGroup || (groupSpan && groupSpan.textContent.trim() === groupSelect.options[groupSelect.selectedIndex].text)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                    checkbox.checked = false;
                }
            });
        }

        groupSelect.addEventListener('change', function() {
            if (sendToAllEmployees.checked) {
                sendToAllEmployees.checked = false;
            }
            updateRecipientsDisplay();
        });

        sendToAllEmployees.addEventListener('change', function() {
            if (this.checked) {
                groupSelect.value = '';
            }
            updateRecipientsDisplay();
        });

        // Выбор всех получателей
        function selectAll() {
            const visibleCheckboxes = recipientsList.querySelectorAll('.recipient-item:not([style*="display: none"]) input[type="checkbox"]');
            visibleCheckboxes.forEach(checkbox => checkbox.checked = true);
        }

        // Снятие выбора со всех получателей
        function deselectAll() {
            const checkboxes = recipientsList.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(checkbox => checkbox.checked = false);
        }

        // Обработка отправки формы
        document.getElementById('smsForm').addEventListener('submit', function(e) {
            const selectedRecipients = recipientsList.querySelectorAll('input[type="checkbox"]:checked');
            const messageText = document.getElementById('messageText').value.trim();
            const sendToAll = sendToAllEmployees.checked;
            const selectedGroup = groupSelect.value;
            
            if (!messageText) {
                e.preventDefault();
                showStatus('Пожалуйста, введите текст сообщения', 'error');
                return;
            }
            
            if (messageText.length > maxLength) {
                e.preventDefault();
                showStatus('Текст сообщения превышает 160 символов', 'error');
                return;
            }
            
            // Проверяем наличие получателей только если не выбрана массовая рассылка
            if (!sendToAll && !selectedGroup && selectedRecipients.length === 0) {
                e.preventDefault();
                showStatus('Пожалуйста, выберите хотя бы одного получателя, группу или опцию "Отправить всем сотрудникам"', 'error');
                return;
            }
            
            // Добавляем скрытое поле для массовой рассылки
            if (sendToAll) {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'sendToAllEmployees';
                hiddenInput.value = '1';
                this.appendChild(hiddenInput);
            }
            
            showStatus('Отправка СМС...', 'success');
        });

        function showStatus(message, type) {
            const statusDiv = document.getElementById('statusMessage');
            statusDiv.textContent = message;
            statusDiv.className = 'status-message status-' + type;
            statusDiv.style.display = 'block';
            
            setTimeout(() => {
                statusDiv.style.display = 'none';
            }, 5000);
        }
    </script>
</body>
</html>

