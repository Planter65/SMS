<?php
/**
 * Единая навигация проекта "Система СМС информирования"
 */

$isLoggedIn = function_exists('isLoggedIn') ? isLoggedIn() : false;
$currentUser = $isLoggedIn && function_exists('getCurrentUser') ? getCurrentUser() : null;
$userRole = $currentUser['role'] ?? 'guest';
$username = $currentUser['username'] ?? '';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// Получаем название выбранного предприятия для администратора
$selectedCompanyName = '';
if ($isLoggedIn && $userRole === 'admin' && function_exists('getSelectedCompany')) {
    $companyId = getSelectedCompany();
    if ($companyId) {
        require_once 'config.php';
        $conn = connectToDatabase();
        $stmt = $conn->prepare("SELECT CompanyName FROM companies WHERE CompanyID = ?");
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $selectedCompanyName = htmlspecialchars($row['CompanyName']);
        }
        $stmt->close();
        $conn->close();
    }
}

function isActive($page) {
    global $currentPage;
    return $currentPage === $page ? 'active' : '';
}
?>

<nav class="main-navigation">
    <div class="nav-container">
        <div class="nav-brand">
            <span class="brand-icon">📡</span>
            <span>СМС информирование<?php if ($selectedCompanyName): ?> — <?php echo $selectedCompanyName; ?><?php endif; ?></span>
        </div>

        <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Меню">
            ☰
        </button>

        <div class="nav-menu" id="navMenu">
            <ul class="nav-list">
                <?php if (!$isLoggedIn): ?>
                    <li class="nav-item">
                        <a href="login.php" class="nav-link <?php echo isActive('login'); ?>">Обратно</a>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a href="user.php" class="nav-link <?php echo isActive('user'); ?>">Рабочий кабинет</a>
                    </li>
                    <?php if ($userRole === 'admin'): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle">Администрирование ▾</a>
                            <div class="dropdown-menu">
                                
                                <a class="dropdown-link" href="sms_settings.php">Настройки SMS</a>
                                
                                <a class="dropdown-link" href="tb.php">Данные БД</a>
                               
                            </div>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <span class="nav-link">
                            👤 <?php echo htmlspecialchars($username); ?>
                            <span class="pill"><?php echo $userRole === 'admin' ? 'Администратор' : 'Пользователь'; ?></span>
                        </span>
                    </li>
                    <li class="nav-item">
                        <a href="logout.php" class="nav-link">Выйти</a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.getElementById('mobileMenuToggle');
    const menu = document.getElementById('navMenu');
    if (!toggle || !menu) return;
    toggle.addEventListener('click', () => {
        menu.classList.toggle('open');
    });
});
</script>

