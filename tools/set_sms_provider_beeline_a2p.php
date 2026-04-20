<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../sms_providers.php';

$conn = connectToDatabase();
ensureSmsSettingsTable($conn);

$stmt = $conn->prepare('REPLACE INTO sms_settings (setting_key, setting_value) VALUES (?, ?)');
if (!$stmt) {
    throw new RuntimeException('Prepare failed: ' . $conn->error);
}

$pairs = [
    ['SMS_PROVIDER', 'beeline_a2p'],
];

foreach ($pairs as [$k, $v]) {
    $stmt->bind_param('ss', $k, $v);
    if (!$stmt->execute()) {
        throw new RuntimeException('Execute failed for ' . $k . ': ' . $stmt->error);
    }
}

$stmt->close();
$conn->close();

echo "OK: SMS_PROVIDER=beeline_a2p\n";

