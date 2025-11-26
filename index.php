<?php
// Перенаправляем на главный файл системы
header('Location: start.php');
exit;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Система СМС информирования</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }

        .header p {
            font-size: 1.1em;
            opacity: 0.9;
        }

        .main-content {
            padding: 40px;
        }

        .form-section {
            margin-bottom: 40px;
        }

        .form-section h2 {
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #4CAF50;
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
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }

        input[type="text"]:focus, 
        textarea:focus, 
        select:focus {
            outline: none;
            border-color: #4CAF50;
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
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            margin: 10px 5px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(76, 175, 80, 0.3);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
        }

        .btn-secondary:hover {
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.3);
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
            border-radius: 8px;
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

        .nav-buttons {
            text-align: center;
            margin: 20px 0;
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
            .main-content {
                padding: 20px;
            }
            
            .header h1 {
                font-size: 2em;
            }
            
            .stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <?php include 'navigation.php'; ?>
    <div class="container">
        <div class="header">
            <h1>📱 Система СМС информирования</h1>
            <p>Автоматизация процесса отправки СМС-оповещений</p>
        </div>

        <div class="main-content">
            <!-- Навигационные кнопки -->
            <div class="nav-buttons">
                <a href="test_connection.php" class="btn btn-secondary">🔧 Тест подключения</a>
                <a href="tb.php" class="btn btn-secondary">📊 Просмотр данных</a>
                <a href="http://localhost/phpmyadmin" class="btn btn-secondary" target="_blank">🗄️ phpMyAdmin</a>
            </div>

            <!-- Статистика -->
            <div class="stats">
                <?php
                require_once 'config.php';
                
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
                <div class="form-section">
                    <h2>💬 Отправка СМС сообщения</h2>
                    
                    <div class="form-group">
                        <label for="messageText">Текст сообщения:</label>
                        <textarea id="messageText" name="messageText" maxlength="160" placeholder="Введите текст СМС сообщения (максимум 160 символов)" required></textarea>
                        <div class="char-counter" id="charCounter">0 / 160</div>
                    </div>

                    <div class="form-group">
                        <label for="groupSelect">Выберите группу получателей:</label>
                        <select id="groupSelect" name="groupSelect">
                            <option value="">Все получатели</option>
                            <?php
                            try {
                                $conn = connectToDatabase();
                                $result = $conn->query("SELECT * FROM groups ORDER BY GroupName");
                                
                                while ($row = $result->fetch_assoc()) {
                                    echo '<option value="' . $row['GroupID'] . '">' . htmlspecialchars($row['GroupName']) . '</option>';
                                }
                                $conn->close();
                            } catch (Exception $e) {
                                echo '<option value="">Ошибка загрузки групп</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="recipients-section">
                        <h3>📱 Выберите получателей:</h3>
                        <div id="recipientsList">
                            <?php
                            try {
                                $conn = connectToDatabase();
                                $result = $conn->query("
                                    SELECT r.*, g.GroupName 
                                    FROM recipients r 
                                    LEFT JOIN groups g ON r.GroupID = g.GroupID 
                                    ORDER BY r.FullName
                                ");
                                
                                if ($result->num_rows > 0) {
                                    while ($row = $result->fetch_assoc()) {
                                        echo '<div class="recipient-item">';
                                        echo '<input type="checkbox" name="recipients[]" value="' . $row['RecipientID'] . '" id="recipient_' . $row['RecipientID'] . '">';
                                        echo '<div class="recipient-info">';
                                        echo '<div class="recipient-name">' . htmlspecialchars($row['FullName']) . '</div>';
                                        echo '<div class="recipient-phone">' . htmlspecialchars($row['PhoneNumber']) . '</div>';
                                        echo '</div>';
                                        if ($row['GroupName']) {
                                            echo '<span class="recipient-group">' . htmlspecialchars($row['GroupName']) . '</span>';
                                        }
                                        echo '</div>';
                                    }
                                } else {
                                    echo '<p>Получатели не найдены. Добавьте получателей через phpMyAdmin.</p>';
                                }
                                $conn->close();
                            } catch (Exception $e) {
                                echo '<p>Ошибка загрузки получателей: ' . $e->getMessage() . '</p>';
                            }
                            ?>
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
    </div>

    <script>
        // Счетчик символов
        const messageText = document.getElementById('messageText');
        const charCounter = document.getElementById('charCounter');
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

        // Фильтрация получателей по группе
        const groupSelect = document.getElementById('groupSelect');
        const recipientsList = document.getElementById('recipientsList');
        const recipientItems = recipientsList.querySelectorAll('.recipient-item');

        groupSelect.addEventListener('change', function() {
            const selectedGroup = this.value;
            
            recipientItems.forEach(item => {
                const groupSpan = item.querySelector('.recipient-group');
                const checkbox = item.querySelector('input[type="checkbox"]');
                
                if (!selectedGroup || (groupSpan && groupSpan.textContent.trim() === this.options[this.selectedIndex].text)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                    checkbox.checked = false;
                }
            });
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
            
            if (selectedRecipients.length === 0) {
                e.preventDefault();
                showStatus('Пожалуйста, выберите хотя бы одного получателя', 'error');
                return;
            }
            
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