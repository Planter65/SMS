<!DOCTYPE html> 
<html lang="ru"> 
<head> 
    <meta charset="UTF-8"> 
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
    <style> 
        body { 
            font-family: Arial, sans-serif; 
            background: url("fon.gif") center/cover fixed no-repeat, #eef2f7;
            margin: 0; 
            padding: 20px; 
            min-height: 100vh;
        }

        .page-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 30px 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-top: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
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
            background-color: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }

        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
        }

        .section {
            margin-bottom: 30px;
        }

        .section h2 {
            color: #4CAF50;
            border-bottom: 2px solid #4CAF50;
            padding-bottom: 10px;
        }

        table { 
            width: 100%; 
            border-collapse: collapse; 
            background-color: white; 
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1); 
            border-radius: 8px; 
            overflow: hidden; 
            margin-bottom: 20px;
        } 

        th, td { 
            padding: 15px; 
            text-align: left; 
            border-bottom: 1px solid #ddd; 
        } 

        th { 
            background-color: #4CAF50; 
            color: white; 
        } 

        tr:hover { 
            background-color: #f5f5f5; 
        } 

        .empty-message {
            text-align: center;
            color: #666;
            font-style: italic;
            padding: 20px;
        }

        .btn {
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin: 5px;
        }

        .btn:hover {
            background-color: #45a049;
        }

        .nav-buttons {
            text-align: center;
            margin: 20px 0;
        }

        @media (max-width: 600px) { 
            th, td { 
                display: block; 
                width: 100%; 
                box-sizing: border-box; 
            } 

            th { 
                text-align: center; 
            } 
        } 
    </style> 
    <title>Система СМС информирования - Просмотр данных</title> 
    <link rel="stylesheet" href="styles.css">
</head> 
<body class="app-shell"> 
    <?php include 'navigation.php'; ?>

<?php 
require_once 'config.php';

// Подключение к базе данных
$conn = connectToDatabase();

echo '<div class="page-header">';
echo '<div class="header-content">';
echo '<h1>📊 Просмотр данных системы</h1>';
echo '<p>Просмотр всех данных системы СМС информирования</p>';
echo '</div>';
echo '</div>';

echo '<div class="container">';

// 1. Просмотр групп получателей
echo '<div class="section">';
echo '<h2>👥 Группы получателей</h2>';

$sql = "SELECT * FROM `groups` ORDER BY GroupID";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    echo '<table>';
    echo '<thead><tr><th>ID группы</th><th>Название группы</th></tr></thead>';
    echo '<tbody>';

    while ($row = $result->fetch_assoc()) {
        echo '<tr>';
        echo '<td>' . $row['GroupID'] . '</td>';
        echo '<td>' . htmlspecialchars($row['GroupName']) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
} else {
    echo '<div class="empty-message">В таблице групп пока нет данных</div>';
}
echo '</div>';

// 2. Просмотр получателей
echo '<div class="section">';
echo '<h2>📱 Получатели СМС</h2>';

$sql = "SELECT r.*, g.GroupName 
        FROM `recipients` r 
        LEFT JOIN `groups` g ON r.GroupID = g.GroupID 
        ORDER BY r.RecipientID";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    echo '<table>';
    echo '<thead><tr><th>ID</th><th>Номер телефона</th><th>ФИО</th><th>Группа</th></tr></thead>';
    echo '<tbody>';

    while ($row = $result->fetch_assoc()) {
        echo '<tr>';
        echo '<td>' . $row['RecipientID'] . '</td>';
        echo '<td>' . htmlspecialchars($row['PhoneNumber']) . '</td>';
        echo '<td>' . htmlspecialchars($row['FullName']) . '</td>';
        echo '<td>' . htmlspecialchars($row['GroupName'] ?? 'Не указана') . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
} else {
    echo '<div class="empty-message">В таблице получателей пока нет данных</div>';
}
echo '</div>';

// 3. Просмотр сообщений
echo '<div class="section">';
echo '<h2>💬 Сообщения</h2>';

$sql = "SELECT * FROM `messages` ORDER BY MessageID";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    echo '<table>';
    echo '<thead><tr><th>ID сообщения</th><th>Текст сообщения</th></tr></thead>';
    echo '<tbody>';

    while ($row = $result->fetch_assoc()) {
        echo '<tr>';
        echo '<td>' . $row['MessageID'] . '</td>';
        echo '<td>' . htmlspecialchars($row['Text']) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
} else {
    echo '<div class="empty-message">В таблице сообщений пока нет данных</div>';
}
echo '</div>';

// 4. Просмотр логов отправки
echo '<div class="section">';
echo '<h2>📋 Логи отправки</h2>';

$sql = "SELECT ml.*, m.Text as MessageText, r.PhoneNumber, r.FullName 
        FROM `messagelogs` ml 
        LEFT JOIN `messages` m ON ml.MessageID = m.MessageID 
        LEFT JOIN `recipients` r ON ml.RecipientID = r.RecipientID 
        ORDER BY ml.SentDate DESC";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    echo '<table>';
    echo '<thead><tr><th>ID лога</th><th>ID сообщения</th><th>Получатель</th><th>Номер телефона</th><th>Статус</th><th>Дата отправки</th></tr></thead>';
    echo '<tbody>';

    while ($row = $result->fetch_assoc()) {
        echo '<tr>';
        echo '<td>' . $row['LogID'] . '</td>';
        echo '<td>' . $row['MessageID'] . '</td>';
        echo '<td>' . htmlspecialchars($row['FullName'] ?? 'Не указано') . '</td>';
        echo '<td>' . htmlspecialchars($row['PhoneNumber'] ?? 'Не указан') . '</td>';
        echo '<td>' . htmlspecialchars($row['Status']) . '</td>';
        echo '<td>' . $row['SentDate'] . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
} else {
    echo '<div class="empty-message">В таблице логов пока нет данных</div>';
}
echo '</div>';

$conn->close(); 
echo '</div>';
?> 

</body> 
</html>