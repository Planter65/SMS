<?php
require_once 'auth.php';
require_once 'sms_providers.php';

// Доступ только для админа
requireRole('admin');

$success = '';
$error = '';
$settings = loadSmsSettings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $provider = $_POST['provider'] ?? 'emulation';
    $smsruApiId = trim($_POST['smsru_api_id'] ?? '');
    $smscLogin = trim($_POST['smsc_login'] ?? '');
    $smscPassword = trim($_POST['smsc_password'] ?? '');
    $apiKey = trim($_POST['api_key'] ?? '');

    try {
        // Валидация по выбранному провайдеру
        if ($provider === 'smsru' && $smsruApiId === '') {
            throw new Exception('Укажите API ID для SMS.ru');
        }
        if ($provider === 'smscru' && ($smscLogin === '' || $smscPassword === '')) {
            throw new Exception('Укажите логин и пароль для SMSC.ru');
        }
        if ($provider === 'apikey' && $apiKey === '') {
            throw new Exception('Укажите API ключ');
        }

        $conn = connectToDatabase();
        ensureSmsSettingsTable($conn);

        // Сохраняем значения
        $data = [
            'SMS_PROVIDER' => $provider,
            'SMSRU_API_ID' => $smsruApiId,
            'SMSCRU_LOGIN' => $smscLogin,
            'SMSCRU_PASSWORD' => $smscPassword,
            'API_KEY' => $apiKey,
        ];

        $stmt = $conn->prepare("REPLACE INTO sms_settings (setting_key, setting_value) VALUES (?, ?)");
        foreach ($data as $key => $value) {
            $stmt->bind_param('ss', $key, $value);
            $stmt->execute();
        }
        $stmt->close();
        $conn->close();

        $success = 'Настройки сохранены. Проверьте отправку SMS.';
        $settings = loadSmsSettings(); // Обновляем отображение
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Настройки SMS</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .settings-wrapper {
            max-width: 900px;
            margin: 30px auto;
            padding: 20px;
        }
        .card {
            background: #ffffff !important; /* чисто белый фон */
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.1);
            position: relative;
            z-index: 1;
        }
        .card * {
            color: #1f2937;
        }
        .card h1 {
            margin: 0 0 12px;
            color: #2f3542;
        }
        .card p {
            margin: 4px 0 16px;
            color: #555;
        }
        label { font-weight: 600; color: #333; }
        .form-group { margin-bottom: 16px; }
        .helper { color: #666; font-size: 13px; margin-top: 4px; }
        select, input[type="text"], input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e3e6ec;
            border-radius: 10px;
            font-size: 15px;
        }
        .btn {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: #fff;
            padding: 12px 22px;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
        }
        .status {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 10px;
        }
        .status.success { background: #e7f6ed; color: #1e7e34; border: 1px solid #c3e6cb; }
        .status.error { background: #fcebea; color: #cc1f1a; border: 1px solid #f5c6cb; }
        .provider-note {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 12px;
            margin-top: 10px;
            font-size: 14px;
        }
    </style>
</head>
<body class="app-shell">
<?php include 'navigation.php'; ?>

<div class="settings-wrapper">
    <div class="card">
        <h1>🔑 Настройки SMS-провайдера</h1>
        <p>Администратор может выбрать провайдера и ввести ключи без изменения кода.</p>

        <?php if ($success): ?>
            <div class="status success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="status error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="provider">Провайдер</label>
                <select id="provider" name="provider" required>
                    <option value="emulation" <?php echo ($settings['SMS_PROVIDER'] ?? '') === 'emulation' ? 'selected' : ''; ?>>Эмуляция (тест)</option>
                    <option value="smsru" <?php echo ($settings['SMS_PROVIDER'] ?? '') === 'smsru' ? 'selected' : ''; ?>>SMS.ru</option>
                    <option value="smscru" <?php echo ($settings['SMS_PROVIDER'] ?? '') === 'smscru' ? 'selected' : ''; ?>>SMSC.ru</option>
                    <option value="apikey" <?php echo ($settings['SMS_PROVIDER'] ?? '') === 'apikey' ? 'selected' : ''; ?>>API Key (универсальный)</option>
                </select>
                <div class="helper">Выберите провайдера. Для реальной отправки используйте SMS.ru, SMSC.ru или API Key.</div>
            </div>

            <div id="smsruFields" class="provider-fields">
                <div class="form-group">
                    <label for="smsru_api_id">SMS.ru API ID</label>
                    <input type="text" id="smsru_api_id" name="smsru_api_id" value="<?php echo htmlspecialchars($settings['SMSRU_API_ID'] ?? ''); ?>" placeholder="Пример: 91E32E3C-A381-6FCE-B8B6-5752285D0AB5">
                    <div class="helper">Получите API ID в личном кабинете SMS.ru → раздел «API».</div>
                </div>
            </div>

            <div id="smscruFields" class="provider-fields">
                <div class="form-group">
                    <label for="smsc_login">SMSC.ru логин</label>
                    <input type="text" id="smsc_login" name="smsc_login" value="<?php echo htmlspecialchars($settings['SMSCRU_LOGIN'] ?? ''); ?>" placeholder="Логин SMSC.ru">
                </div>
                <div class="form-group">
                    <label for="smsc_password">SMSC.ru пароль</label>
                    <input type="password" id="smsc_password" name="smsc_password" value="<?php echo htmlspecialchars($settings['SMSCRU_PASSWORD'] ?? ''); ?>" placeholder="Пароль SMSC.ru">
                </div>
                <div class="provider-note">Пароль хранится в базе как текст. Используйте отдельный API-пароль в кабинете SMSC.ru.</div>
            </div>

            <div id="apikeyFields" class="provider-fields">
                <div class="form-group">
                    <label for="api_key">API ключ</label>
                    <input type="text" id="api_key" name="api_key" value="<?php echo htmlspecialchars($settings['API_KEY'] ?? ''); ?>" placeholder="Введите API ключ">
                    <div class="helper">Вставьте API ключ от вашего SMS-провайдера. После сохранения SMS рассылка будет работать автоматически.</div>
                </div>
            </div>

            <button type="submit" class="btn">Сохранить настройки</button>
        </form>
    </div>
</div>

<script>
    const providerSelect = document.getElementById('provider');
    const smsruFields = document.getElementById('smsruFields');
    const smscruFields = document.getElementById('smscruFields');
    const apikeyFields = document.getElementById('apikeyFields');

    function toggleFields() {
        const value = providerSelect.value;
        smsruFields.style.display = value === 'smsru' ? 'block' : 'none';
        smscruFields.style.display = value === 'smscru' ? 'block' : 'none';
        apikeyFields.style.display = value === 'apikey' ? 'block' : 'none';
    }

    providerSelect.addEventListener('change', toggleFields);
    toggleFields();
</script>
</body>
</html>

