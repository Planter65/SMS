<?php
require_once 'auth.php';

// Если пользователь уже авторизован, перенаправляем на соответствующую страницу
if (isLoggedIn()) {
    $user = getCurrentUser();
    if ($user['role'] === 'admin') {
        header('Location: admin.php');
    } else {
        header('Location: user.php');
    }
    exit;
} else {
    // Если не авторизован, перенаправляем на страницу входа
    header('Location: login.php');
    exit;
}
?>
