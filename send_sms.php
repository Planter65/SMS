<?php
require_once 'config.php';
require_once 'sms_providers.php';
require_once 'auth.php';

// Установка заголовков для JSON ответа
header('Content-Type: application/json; charset=utf-8');

// Проверка метода запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
    exit;
}

try {
    $smsSettings = loadSmsSettings();
    $providerUsed = (string)($smsSettings['SMS_PROVIDER'] ?? (defined('SMS_PROVIDER') ? SMS_PROVIDER : 'emulation'));

    // Получение данных из формы
    $messageText = trim($_POST['messageText'] ?? '');
    $selectedRecipients = $_POST['recipients'] ?? [];
    $selectedGroup = $_POST['groupSelect'] ?? '';
    $sendToAllEmployees = isset($_POST['sendToAllEmployees']) && $_POST['sendToAllEmployees'] === '1';

    // Валидация данных
    if (empty($messageText)) {
        throw new Exception('Текст сообщения не может быть пустым');
    }

    if (strlen($messageText) > 600) {
        throw new Exception('Текст сообщения превышает 600 символов');
    }

    // Подключение к базе данных
    $conn = connectToDatabase();

    // Сохранение сообщения в базу данных
    $stmt = $conn->prepare("INSERT INTO messages (Text) VALUES (?)");
    $stmt->bind_param("s", $messageText);
    
    if (!$stmt->execute()) {
        throw new Exception('Ошибка сохранения сообщения: ' . $stmt->error);
    }
    
    $messageId = $conn->insert_id;
    $stmt->close();

    // Получение информации о получателях
    $recipients = [];
    
    // Если выбрана рассылка всем сотрудникам
    if ($sendToAllEmployees) {
        // Получаем всех получателей из группы "Сотрудники" (GroupID = 1)
        $stmt = $conn->prepare("SELECT RecipientID, PhoneNumber, FullName FROM recipients WHERE GroupID = 1 AND PhoneNumber IS NOT NULL AND PhoneNumber != ''");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $recipients[] = $row;
        }
        $stmt->close();
    }
    // Если выбрана группа
    elseif (!empty($selectedGroup)) {
        $stmt = $conn->prepare("SELECT RecipientID, PhoneNumber, FullName FROM recipients WHERE GroupID = ? AND PhoneNumber IS NOT NULL AND PhoneNumber != ''");
        $stmt->bind_param("i", $selectedGroup);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $recipients[] = $row;
        }
        $stmt->close();
    }
    // Если выбраны конкретные получатели
    elseif (!empty($selectedRecipients)) {
        $placeholders = str_repeat('?,', count($selectedRecipients) - 1) . '?';
        $stmt = $conn->prepare("SELECT RecipientID, PhoneNumber, FullName FROM recipients WHERE RecipientID IN ($placeholders) AND PhoneNumber IS NOT NULL AND PhoneNumber != ''");
        $stmt->bind_param(str_repeat('i', count($selectedRecipients)), ...$selectedRecipients);
        
        if (!$stmt->execute()) {
            throw new Exception('Ошибка получения данных получателей: ' . $stmt->error);
        }
        
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $recipients[] = $row;
        }
        $stmt->close();
    }
    
    // Проверка наличия получателей
    if (empty($recipients)) {
        throw new Exception('Не выбран ни один получатель или у получателей отсутствуют номера телефонов');
    }

    // Отправка SMS через выбранный провайдер (настраивается в config.php)
    $successCount = 0;
    $errorCount = 0;
    $logs = [];

    // Получаем название отправителя (предприятия)
    $companyName = '';
    if (function_exists('getSelectedCompanyName')) {
        $companyName = getSelectedCompanyName();
    }
    
    foreach ($recipients as $recipient) {
        // Добавляем название отправителя в конец сообщения
        $messageWithSender = $messageText;
        if (!empty($companyName)) {
            $senderSuffix = ' ' . $companyName;
            // Проверяем, не превышает ли сообщение лимит в 600 символов
            if (strlen($messageText . $senderSuffix) <= 600) {
                $messageWithSender = $messageText . $senderSuffix;
            }
        }
        
        // Отправка SMS через выбранный провайдер
        $result = sendSms($recipient['PhoneNumber'], $messageWithSender);
        
        // Определяем статус для сохранения в БД (для Beeline "accepted" ≠ "Доставлено")
        $status = (string)($result['status'] ?? ($result['success'] ? 'Отправлено' : 'Ошибка'));
        
        // Сохранение лога отправки
        $stmt = $conn->prepare("INSERT INTO messagelogs (MessageID, RecipientID, Status, SentDate) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iis", $messageId, $recipient['RecipientID'], $status);
        
        if ($stmt->execute()) {
            $logs[] = [
                'recipient' => $recipient['FullName'],
                'phone' => $recipient['PhoneNumber'],
                'status' => $status,
                'message' => $result['message'] ?? ''
            ];
            
            if ($result['success']) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }
        
        $stmt->close();
    }

    $conn->close();

    // Формирование ответа
    $warning = null;
    if ($providerUsed === 'emulation') {
        $warning = 'Сейчас выбран провайдер emulation — реальные SMS не отправляются. Измените SMS_PROVIDER в таблице sms_settings или включите страницу настроек SMS.';
    }

    $response = [
        'success' => $successCount > 0,
        'message' => $successCount > 0
            ? "СМС отправлено: {$successCount}, ошибок: {$errorCount}"
            : "Не удалось отправить SMS. Ошибок: {$errorCount}",
        'details' => [
            'provider' => $providerUsed,
            'warning' => $warning,
            'messageId' => $messageId,
            'totalRecipients' => count($recipients),
            'successCount' => $successCount,
            'errorCount' => $errorCount,
            'logs' => $logs
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

// Функция simulateSmsSending() удалена - теперь используется модуль sms_providers.php
?> 