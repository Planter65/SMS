<?php
require_once 'auth.php';
require_once 'config.php';

requireAuth();

$user = function_exists('getCurrentUser') ? getCurrentUser() : null;
$error = '';
$stats = [
    'recipients' => 0,
    'groups' => 0,
    'messages' => 0,
    'sent' => 0,
];
$groups = [];
$recipients = [];

try {
    $conn = connectToDatabase();

    $stats['recipients'] = (int)($conn->query("SELECT COUNT(*) AS c FROM recipients")->fetch_assoc()['c'] ?? 0);
    $stats['groups'] = (int)($conn->query("SELECT COUNT(*) AS c FROM groups")->fetch_assoc()['c'] ?? 0);
    $stats['messages'] = (int)($conn->query("SELECT COUNT(*) AS c FROM messages")->fetch_assoc()['c'] ?? 0);
    $stats['sent'] = (int)($conn->query("SELECT COUNT(*) AS c FROM messagelogs")->fetch_assoc()['c'] ?? 0);

    $groupRes = $conn->query("SELECT GroupID, GroupName FROM groups ORDER BY GroupName");
    while ($row = $groupRes->fetch_assoc()) {
        $groups[] = $row;
    }

    $recRes = $conn->query("
        SELECT r.RecipientID, r.FullName, r.PhoneNumber, g.GroupName
        FROM recipients r
        LEFT JOIN groups g ON r.GroupID = g.GroupID
        ORDER BY r.FullName
    ");
    while ($row = $recRes->fetch_assoc()) {
        $recipients[] = $row;
    }

    $conn->close();
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>СМС информирование — Дашборд</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="app-shell">
<?php include 'navigation.php'; ?>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">Центр рассылок</h1>
        <p class="page-subtitle">Введите текст, выберите аудиторию и отправьте СМС за пару кликов.</p>
    </div>

    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['recipients']; ?></div>
            <div class="stat-label">Получателей</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['groups']; ?></div>
            <div class="stat-label">Групп</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['messages']; ?></div>
            <div class="stat-label">Сообщений в базе</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $stats['sent']; ?></div>
            <div class="stat-label">Отправлено СМС</div>
        </div>
    </div>

    <div class="toolbar" style="margin: 16px 0 24px;">
        <div class="pill">👤 <?php echo htmlspecialchars($user['username'] ?? ''); ?></div>
        <div class="actions">
            <?php if (($user['role'] ?? '') === 'admin'): ?>
                <a class="btn btn-ghost" href="admin.php">Панель администратора</a>
                <a class="btn btn-ghost" href="test_connection.php">Тест подключения</a>
                <a class="btn btn-ghost" href="tb.php">Просмотр данных</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="page-grid">
        <div class="card surface">
            <h3 style="margin-top:0;">💬 Новое СМС</h3>
            <form id="smsComposer">
                <div class="form-group">
                    <label for="messageText">Текст сообщения (до 600 символов)</label>
                    <textarea id="messageText" name="messageText" maxlength="600" required placeholder="Например: Сегодня в 10:00 состоится плановое совещание." disabled></textarea>
                    <div class="char-counter" id="charCounter">0 / 600</div>
                </div>

                <div class="toolbar" style="margin-top: 12px;">
                    <div class="pill">📱 Выбрано: <span id="selectedCount">0</span></div>
                    <div class="actions">
                        <button type="button" class="btn btn-secondary" id="selectAllBtn">Выбрать всех</button>
                        <button type="button" class="btn btn-ghost" id="clearSelectionBtn">Очистить</button>
                    </div>
                </div>

                <div id="statusMessage" class="alert" style="display:none; margin-top: 14px;"></div>

                <div style="margin-top: 16px; display:flex; gap:12px; flex-wrap:wrap;">
                    <button type="submit" class="btn">📤 Отправить сообщение</button>
                    <button type="reset" class="btn btn-ghost">Сбросить форму</button>
                </div>
            </form>
        </div>

        <div class="card surface">
            <div class="toolbar" style="margin-bottom:10px;">
                <div style="flex:1;">
                    <input type="search" id="recipientSearch" placeholder="Поиск по ФИО или номеру">
                </div>
                <div style="width:200px;">
                    <select id="filterGroup">
                        <option value="">Все группы</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?php echo htmlspecialchars($group['GroupName']); ?>">
                                <?php echo htmlspecialchars($group['GroupName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="recipient-list" id="recipientsList">
                <?php if (count($recipients) === 0): ?>
                    <p style="color:#6b7280;">Получатели отсутствуют. Добавьте их через phpMyAdmin или синхронизацию.</p>
                <?php else: ?>
                    <?php foreach ($recipients as $r): ?>
                        <label class="recipient-card"
                               data-name="<?php echo htmlspecialchars(mb_strtolower($r['FullName'])); ?>"
                               data-phone="<?php echo htmlspecialchars($r['PhoneNumber']); ?>"
                               data-group="<?php echo htmlspecialchars(mb_strtolower($r['GroupName'] ?? '')); ?>"
                               data-group-id="<?php echo htmlspecialchars($r['GroupID'] ?? ''); ?>">
                            <input type="checkbox" name="recipients[]" value="<?php echo $r['RecipientID']; ?>">
                            <div class="recipient-meta">
                                <div class="recipient-name"><?php echo htmlspecialchars($r['FullName']); ?></div>
                                <div class="recipient-phone"><?php echo htmlspecialchars($r['PhoneNumber']); ?></div>
                            </div>
                            <?php if (!empty($r['GroupName'])): ?>
                                <span class="badge badge-muted"><?php echo htmlspecialchars($r['GroupName']); ?></span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
const messageInput = document.getElementById('messageText');
const charCounter = document.getElementById('charCounter');
const recipientsList = document.getElementById('recipientsList');
const selectedCountEl = document.getElementById('selectedCount');
const statusMessage = document.getElementById('statusMessage');

const searchInput = document.getElementById('recipientSearch');
const filterGroup = document.getElementById('filterGroup');
const selectAllBtn = document.getElementById('selectAllBtn');
const clearSelectionBtn = document.getElementById('clearSelectionBtn');
const sendForm = document.getElementById('smsComposer');

function updateCounter() {
    const len = messageInput.value.length;
    charCounter.textContent = `${len} / 600`;
    charCounter.classList.toggle('warning', len > 130 && len <= 150);
    charCounter.classList.toggle('danger', len > 150);
}

function updateSelectedCount() {
    const count = recipientsList.querySelectorAll('input[type="checkbox"]:checked').length;
    selectedCountEl.textContent = count;
    messageInput.disabled = count === 0;
}

function filterRecipients() {
    const q = (searchInput.value || '').toLowerCase().trim();
    const g = (filterGroup.value || '').toLowerCase().trim();

    recipientsList.querySelectorAll('.recipient-card').forEach(card => {
        const name = card.dataset.name || '';
        const phone = card.dataset.phone || '';
        const group = card.dataset.group || '';
        const matchesText = !q || name.includes(q) || phone.includes(q);
        const matchesGroup = !g || group === g;
        card.style.display = matchesText && matchesGroup ? 'flex' : 'none';
    });
}

function selectAllVisible() {
    recipientsList.querySelectorAll('.recipient-card').forEach(card => {
        if (card.style.display !== 'none') {
            const cb = card.querySelector('input[type="checkbox"]');
            if (cb) cb.checked = true;
        }
    });
    updateSelectedCount();
}

function clearSelection() {
    recipientsList.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
    updateSelectedCount();
}

function showStatus(text, success = true) {
    if (!statusMessage) return;
    statusMessage.textContent = text;
    statusMessage.className = 'alert ' + (success ? 'alert-success' : 'alert-error');
    statusMessage.style.display = 'block';
    setTimeout(() => statusMessage.style.display = 'none', 5000);
}

if (messageInput) {
    messageInput.addEventListener('input', updateCounter);
    updateCounter();
}
recipientsList?.addEventListener('change', updateSelectedCount);
searchInput?.addEventListener('input', filterRecipients);
filterGroup?.addEventListener('change', filterRecipients);
selectAllBtn?.addEventListener('click', selectAllVisible);
clearSelectionBtn?.addEventListener('click', clearSelection);

sendForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const selectedRecipients = recipientsList.querySelectorAll('input[type="checkbox"]:checked');
    if (selectedRecipients.length === 0) {
        showStatus('Выберите хотя бы одного получателя.', false);
        return;
    }
    const form = new FormData();
    form.append('messageText', messageInput.value.trim());
    form.append('groupSelect', ''); // выбор группы осуществляется через фильтр справа

    selectedRecipients.forEach(cb => {
        form.append('recipients[]', cb.value);
    });

    try {
        const res = await fetch('send_sms.php', { method: 'POST', body: form });
        const data = await res.json();
        if (!data.success) {
            showStatus(data.message || 'Не удалось отправить сообщение', false);
            return;
        }
        showStatus(`${data.message} | Успешно: ${data.details.successCount}, Ошибок: ${data.details.errorCount}`, true);
        sendForm.reset();
        clearSelection();
        updateCounter();
    } catch (err) {
        showStatus('Ошибка отправки: ' + (err.message || err), false);
    }
});
</script>
</body>
</html>

