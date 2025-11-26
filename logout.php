<?php
require_once 'auth.php';

// Выход из системы
logout();
header('Location: login.php');
exit;
?>
