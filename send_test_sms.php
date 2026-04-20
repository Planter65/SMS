<?php
declare(strict_types=1);

// Test page for Beeline A2P HTTPS API using provided QTSMS classes.

$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'local_beeline_sms_config.php';
$exampleConfigPath = __DIR__ . DIRECTORY_SEPARATOR . 'local_beeline_sms_config.example.php';

if (!file_exists($configPath)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    $exampleFile = basename($exampleConfigPath);
    $realFile = basename($configPath);
    echo "<h2>Не найден конфиг: {$realFile}</h2>";
    echo "<p>Скопируйте <code>{$exampleFile}</code> в <code>{$realFile}</code> и заполните логин/пароль/host/sender.</p>";
    exit;
}

/** @var array $cfg */
$cfg = [];
require $configPath;

require_once __DIR__ . '/API/HTTPS/test/QTSMS.class.php';

function normalizePhone(string $raw): string {
    $p = preg_replace('/[^0-9+]/', '', trim($raw));
    if ($p === '') return '';
    // allow 79..., 89..., +79...
    if ($p[0] !== '+') {
        if (preg_match('/^[78]/', $p)) {
            $p = '+7' . substr($p, 1);
        } else {
            $p = '+7' . $p;
        }
    }
    return $p;
}

$error = '';
$resultXml = '';
$sentSmsId = '';
$sentSmsGroupId = '';

$defaultPhone = '79505850016';
$phone = isset($_POST['phone']) ? (string)$_POST['phone'] : $defaultPhone;
$message = isset($_POST['message']) ? (string)$_POST['message'] : 'Тестовое сообщение';
$sender = isset($_POST['sender']) ? (string)$_POST['sender'] : (string)($cfg['sender'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = (string)($cfg['login'] ?? '');
    $password = (string)($cfg['password'] ?? '');
    $host = (string)($cfg['host'] ?? '');

    $phoneNorm = normalizePhone($phone);
    $message = trim($message);
    $sender = trim($sender);

    if ($login === '' || $password === '' || $host === '') {
        $error = 'В local_beeline_sms_config.php должны быть заполнены login, password, host.';
    } elseif ($phoneNorm === '' || !preg_match('/^\+\d{10,15}$/', $phoneNorm)) {
        $error = 'Некорректный номер. Пример: +79505850016 или 79505850016.';
    } elseif ($message === '') {
        $error = 'Введите текст сообщения.';
    } elseif ($sender === '') {
        $error = 'Введите sender (подпись отправителя), если она требуется провайдером.';
    } else {
        try {
            $qtsms = new QTSMS($login, $password, $host);
            // Returns XML (string)
            $resultXml = (string)$qtsms->post_message($message, $phoneNorm, $sender);

            // Extract ids for convenience
            try {
                $sx = @simplexml_load_string($resultXml);
                if ($sx !== false) {
                    $res = $sx->result ?? null;
                    if ($res) {
                        $attrs = $res->attributes();
                        if ($attrs && isset($attrs['sms_group_id'])) $sentSmsGroupId = (string)$attrs['sms_group_id'];
                        if (isset($res->sms)) {
                            $smsAttrs = $res->sms->attributes();
                            if ($smsAttrs && isset($smsAttrs['id'])) $sentSmsId = (string)$smsAttrs['id'];
                        }
                    }
                }
            } catch (Throwable $e) {
                // ignore parsing errors, raw xml is still displayed
            }
        } catch (Throwable $e) {
            $error = 'Ошибка отправки: ' . $e->getMessage();
        }
    }
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Тест отправки SMS (Beeline A2P HTTPS)</title>
    <style>
        body { font-family: Segoe UI, Tahoma, Arial, sans-serif; margin: 24px; background: #f6f7fb; }
        .card { max-width: 820px; background: #fff; border: 1px solid #e6e8ef; border-radius: 12px; padding: 18px 18px 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); }
        h2 { margin: 0 0 14px; }
        label { display:block; font-weight: 600; margin: 12px 0 6px; }
        input, textarea { width: 100%; padding: 10px 12px; border: 1px solid #cfd6e6; border-radius: 10px; font-size: 14px; }
        textarea { min-height: 110px; resize: vertical; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .btn { margin-top: 14px; padding: 10px 14px; border: 0; border-radius: 10px; background: #2e7d32; color: #fff; font-weight: 700; cursor: pointer; }
        .btn:hover { filter: brightness(0.95); }
        .hint { color: #555; font-size: 13px; margin-top: 10px; }
        .err { margin-top: 12px; padding: 10px 12px; background: #fdecea; border: 1px solid #f5c2c7; border-radius: 10px; color: #842029; }
        pre { white-space: pre-wrap; word-break: break-word; padding: 12px; background: #0b1020; color: #d7e2ff; border-radius: 10px; overflow:auto; }
        code { background: #f1f3f8; padding: 2px 6px; border-radius: 6px; }
        .warn { margin-top: 12px; padding: 10px 12px; background: #fff3cd; border: 1px solid #ffecb5; border-radius: 10px; color: #664d03; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Тест отправки SMS (Beeline A2P HTTPS)</h2>

        <div class="warn">
            Конфиг с паролем хранится в <code>local_beeline_sms_config.php</code> и добавлен в <code>.gitignore</code>.
        </div>

        <?php if ($error): ?>
            <div class="err"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="row">
                <div>
                    <label for="phone">Телефон</label>
                    <input id="phone" name="phone" value="<?php echo htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" placeholder="+79505850016">
                </div>
                <div>
                    <label for="sender">Sender (подпись)</label>
                    <input id="sender" name="sender" value="<?php echo htmlspecialchars($sender, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" placeholder="Напр. LogicT">
                </div>
            </div>

            <label for="message">Текст SMS</label>
            <textarea id="message" name="message"><?php echo htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>

            <button class="btn" type="submit">Отправить тестовое SMS</button>
        </form>

        <div class="hint">
            Endpoint берется из примеров провайдера: <code>https://a2p-sms-https.beeline.ru/proto/http/</code>. Если у вас в кабинете/договоре другой — поменяйте в конфиге.
        </div>

        <?php if ($resultXml): ?>
            <h3>Ответ сервера (XML)</h3>
            <pre><?php echo htmlspecialchars($resultXml, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></pre>

            <?php if ($sentSmsId || $sentSmsGroupId): ?>
                <div class="hint" style="margin-top:10px;">
                    <?php if ($sentSmsId): ?>
                        sms_id: <code><?php echo htmlspecialchars($sentSmsId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></code>
                    <?php endif; ?>
                    <?php if ($sentSmsGroupId): ?>
                        <?php if ($sentSmsId) echo " &nbsp; "; ?>
                        sms_group_id: <code><?php echo htmlspecialchars($sentSmsGroupId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></code>
                    <?php endif; ?>
                    <br>
                    <a href="check_beeline_status.php?<?php
                        $qs = [];
                        if ($sentSmsId) $qs[] = 'sms_id=' . rawurlencode($sentSmsId);
                        if (!$sentSmsId && $sentSmsGroupId) $qs[] = 'sms_group_id=' . rawurlencode($sentSmsGroupId);
                        echo implode('&', $qs);
                    ?>">Проверить статус доставки</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>

