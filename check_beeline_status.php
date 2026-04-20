<?php
declare(strict_types=1);

// Beeline A2P status checker using QTSMS wrapper.

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

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function extractStatusesFromXml(string $xml): array {
    $out = [];
    $sx = @simplexml_load_string($xml);
    if ($sx === false) return $out;

    // Best-effort: try to find <sms ...> nodes anywhere
    $smsNodes = $sx->xpath('//sms');
    if (!$smsNodes) return $out;

    foreach ($smsNodes as $n) {
        $a = $n->attributes();
        $out[] = [
            'id' => isset($a['id']) ? (string)$a['id'] : '',
            'phone' => isset($a['phone']) ? (string)$a['phone'] : '',
            'status' => isset($a['status']) ? (string)$a['status'] : (isset($a['sms_status']) ? (string)$a['sms_status'] : ''),
            'status_text' => isset($a['status_text']) ? (string)$a['status_text'] : '',
            'smstype' => isset($a['smstype']) ? (string)$a['smstype'] : '',
        ];
    }
    return $out;
}

$error = '';
$resultXml = '';
$statuses = [];

$smsId = isset($_GET['sms_id']) ? trim((string)$_GET['sms_id']) : '';
$smsGroupId = isset($_GET['sms_group_id']) ? trim((string)$_GET['sms_group_id']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $smsId = trim((string)($_POST['sms_id'] ?? ''));
    $smsGroupId = trim((string)($_POST['sms_group_id'] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim((string)($cfg['login'] ?? ''));
    $password = trim((string)($cfg['password'] ?? ''));
    $host = trim((string)($cfg['host'] ?? ''));

    if ($login === '' || $password === '' || $host === '') {
        $error = 'В local_beeline_sms_config.php должны быть заполнены login, password, host.';
    } elseif ($smsId === '' && $smsGroupId === '') {
        $error = 'Укажите sms_id или sms_group_id.';
    } else {
        try {
            $qtsms = new QTSMS($login, $password, $host);
            if ($smsId !== '') {
                $resultXml = (string)$qtsms->status_sms_id($smsId);
            } else {
                $resultXml = (string)$qtsms->status_sms_group_id($smsGroupId);
            }
            $statuses = extractStatusesFromXml($resultXml);
        } catch (Throwable $e) {
            $error = 'Ошибка запроса статуса: ' . $e->getMessage();
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
    <title>Проверка статуса SMS (Beeline A2P)</title>
    <style>
        body { font-family: Segoe UI, Tahoma, Arial, sans-serif; margin: 24px; background: #f6f7fb; }
        .card { max-width: 980px; background: #fff; border: 1px solid #e6e8ef; border-radius: 12px; padding: 18px 18px 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); }
        h2 { margin: 0 0 10px; }
        label { display:block; font-weight: 600; margin: 12px 0 6px; }
        input { width: 100%; padding: 10px 12px; border: 1px solid #cfd6e6; border-radius: 10px; font-size: 14px; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .btn { margin-top: 14px; padding: 10px 14px; border: 0; border-radius: 10px; background: #1f6feb; color: #fff; font-weight: 700; cursor: pointer; }
        .btn:hover { filter: brightness(0.95); }
        .hint { color: #555; font-size: 13px; margin-top: 10px; }
        .err { margin-top: 12px; padding: 10px 12px; background: #fdecea; border: 1px solid #f5c2c7; border-radius: 10px; color: #842029; }
        pre { white-space: pre-wrap; word-break: break-word; padding: 12px; background: #0b1020; color: #d7e2ff; border-radius: 10px; overflow:auto; }
        code { background: #f1f3f8; padding: 2px 6px; border-radius: 6px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { text-align: left; border-bottom: 1px solid #eef1f6; padding: 10px 8px; font-size: 14px; }
        th { color: #374151; background: #fafbff; position: sticky; top: 0; }
        .ok { color: #1f7a1f; font-weight: 700; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Проверка статуса SMS (Beeline A2P)</h2>
        <div class="hint">
            Введите <code>sms_id</code> (конкретная SMS) или <code>sms_group_id</code> (группа рассылки).
        </div>

        <?php if ($error): ?>
            <div class="err"><?php echo h($error); ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="row">
                <div>
                    <label for="sms_id">sms_id</label>
                    <input id="sms_id" name="sms_id" value="<?php echo h($smsId); ?>" placeholder="Напр. 3936147369708564039">
                </div>
                <div>
                    <label for="sms_group_id">sms_group_id</label>
                    <input id="sms_group_id" name="sms_group_id" value="<?php echo h($smsGroupId); ?>" placeholder="Напр. 3936148469220191815">
                </div>
            </div>
            <button class="btn" type="submit">Проверить статус</button>
        </form>

        <?php if ($statuses): ?>
            <h3 style="margin-top:16px;">Результат (разбор)</h3>
            <table>
                <thead>
                    <tr>
                        <th>id</th>
                        <th>phone</th>
                        <th>status</th>
                        <th>status_text</th>
                        <th>smstype</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($statuses as $s): ?>
                    <tr>
                        <td><?php echo h($s['id']); ?></td>
                        <td><?php echo h($s['phone']); ?></td>
                        <td class="<?php echo ($s['status'] !== '' && strtolower($s['status']) !== 'error') ? 'ok' : ''; ?>"><?php echo h($s['status']); ?></td>
                        <td><?php echo h($s['status_text']); ?></td>
                        <td><?php echo h($s['smstype']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($resultXml): ?>
            <h3 style="margin-top:16px;">Ответ сервера (XML)</h3>
            <pre><?php echo h($resultXml); ?></pre>
        <?php endif; ?>
    </div>
</body>
</html>

