<?php
require_once 'auth.php';

// Новая точка входа: общий дашборд
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

header('Location: login.php');
exit;
?>
