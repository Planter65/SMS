<?php
require_once 'config.php';

// Установка заголовков для JSON ответа
header('Content-Type: application/json; charset=utf-8');

// Проверка метода запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
    exit;
}

try {
    // Получение данных из формы
    $messageText = trim($_POST['messageText'] ?? '');
    $selectedRecipients = $_POST['recipients'] ?? [];
    $selectedGroup = $_POST['groupSelect'] ?? '';

    // Валидация данных
    if (empty($messageText)) {
        throw new Exception('Текст сообщения не может быть пустым');
    }

    if (strlen($messageText) > 160) {
        throw new Exception('Текст сообщения превышает 160 символов');
    }

    if (empty($selectedRecipients)) {
        throw new Exception('Не выбран ни один получатель');
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
    if (!empty($selectedRecipients)) {
        $placeholders = str_repeat('?,', count($selectedRecipients) - 1) . '?';
        $stmt = $conn->prepare("SELECT RecipientID, PhoneNumber, FullName FROM recipients WHERE RecipientID IN ($placeholders)");
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

    // Эмуляция отправки СМС (в реальной системе здесь будет интеграция с API оператора)
    $successCount = 0;
    $errorCount = 0;
    $logs = [];

    foreach ($recipients as $recipient) {
        // Эмуляция отправки СМС
        $status = simulateSmsSending($recipient['PhoneNumber'], $messageText);
        
        // Сохранение лога отправки
        $stmt = $conn->prepare("INSERT INTO messagelogs (MessageID, RecipientID, Status, SentDate) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iis", $messageId, $recipient['RecipientID'], $status);
        
        if ($stmt->execute()) {
            $logs[] = [
                'recipient' => $recipient['FullName'],
                'phone' => $recipient['PhoneNumber'],
                'status' => $status
            ];
            
            if ($status === 'Доставлено') {
                $successCount++;
            } else {
                $errorCount++;
            }
        }
        
        $stmt->close();
    }

    $conn->close();

    // Формирование ответа
    $response = [
        'success' => true,
        'message' => "СМС отправлено успешно!",
        'details' => [
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

/**
 * Эмуляция отправки СМС
 * В реальной системе здесь будет интеграция с API оператора связи
 */
function simulateSmsSending($phoneNumber, $message) {
    // Эмуляция различных статусов отправки
    $statuses = ['Доставлено', 'Ошибка', 'В очереди'];
    $weights = [80, 15, 5]; // 80% успех, 15% ошибка, 5% в очереди
    
    $random = mt_rand(1, 100);
    $cumulative = 0;
    
    for ($i = 0; $i < count($statuses); $i++) {
        $cumulative += $weights[$i];
        if ($random <= $cumulative) {
            return $statuses[$i];
        }
    }
    
    return 'Доставлено'; // По умолчанию
}
?> 