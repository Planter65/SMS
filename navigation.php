<?php
/**
 * Компонент навигации для системы СМС информирования
 * Единая навигация для всех страниц с учетом ролей пользователей
 */

// Проверяем авторизацию
$isLoggedIn = function_exists('isLoggedIn') ? isLoggedIn() : false;
$currentUser = $isLoggedIn && function_exists('getCurrentUser') ? getCurrentUser() : null;
$userRole = $currentUser ? $currentUser['role'] : 'guest';
$username = $currentUser ? $currentUser['username'] : '';

// Определяем текущую страницу
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// Функция для определения активной ссылки
function isActive($page) {
    global $currentPage;
    return $currentPage === $page ? 'active' : '';
}

// Функция для проверки доступности ссылки для роли
function canAccess($requiredRole) {
    global $userRole;
    
    if ($requiredRole === 'guest') return true;
    if ($requiredRole === 'user' && in_array($userRole, ['user', 'admin'])) return true;
    if ($requiredRole === 'admin' && $userRole === 'admin') return true;
    
    return false;
}
?>

<!-- Навигационное меню -->
<nav class="main-navigation">
    <div class="nav-container">
        <!-- Логотип и название -->
        <div class="nav-brand">
            <a href="<?php echo $isLoggedIn ? ($userRole === 'admin' ? 'admin.php' : 'user.php') : 'login.php'; ?>" class="brand-link">
                <span class="brand-icon">📱</span>
                <span class="brand-text">СМС Система</span>
            </a>
        </div>

        <!-- Мобильное меню (гамбургер) -->
        <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Открыть меню">
            <span class="hamburger-line"></span>
            <span class="hamburger-line"></span>
            <span class="hamburger-line"></span>
        </button>

        <!-- Основное меню -->
        <div class="nav-menu" id="navMenu">
            <ul class="nav-list">
                <?php if (!$isLoggedIn): ?>
                    <!-- Меню для неавторизованных пользователей -->
                    <li class="nav-item">
                        <a href="login.php" class="nav-link <?php echo isActive('login'); ?>">
                            <span class="nav-icon">🔐</span>
                            <span class="nav-text">Вход</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="index.php" class="nav-link <?php echo isActive('index'); ?>">
                            <span class="nav-icon">🏠</span>
                            <span class="nav-text">Главная</span>
                        </a>
                    </li>
                <?php else: ?>
                    <!-- Меню для авторизованных пользователей -->
                    


                    

                    <!-- Административные функции -->
                    <?php if (canAccess('admin')): ?>
                    <li class="nav-item dropdown">
                        <a href="#" class="nav-link dropdown-toggle" data-toggle="dropdown">
                            <span class="nav-icon">⚙️</span>
                            <span class="nav-text">Администрирование</span>
                            <span class="dropdown-arrow">▼</span>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a href="admin.php" class="dropdown-link">Панель администратора</a></li>
                            <li><a href="integration.php" class="dropdown-link">Интеграция</a></li>
                            <li><a href="tb.php" class="dropdown-link">Просмотр данных</a></li>
                            <li><a href="test_connection.php" class="dropdown-link">Тест подключения</a></li>
                        </ul>
                    </li>
                    <?php endif; ?>

                    <!-- Пользовательские сообщения -->
                    <?php if (canAccess('user')): ?>
                    <li class="nav-item">
                        <a href="user.php" class="nav-link <?php echo isActive('user'); ?>">
                            <span class="nav-icon">💬</span>
                            <span class="nav-text">Мои сообщения</span>
                        </a>
                    </li>
                    <?php endif; ?>

                    <!-- Информация о пользователе -->
                    <li class="nav-item user-info">
                        <div class="user-profile">
                            <span class="user-avatar">👤</span>
                            <span class="user-name"><?php echo htmlspecialchars($username); ?></span>
                            <span class="user-role"><?php echo $userRole === 'admin' ? 'Администратор' : 'Пользователь'; ?></span>
                        </div>
                    </li>

                    <!-- Выход -->
                    <li class="nav-item">
                        <a href="logout.php" class="nav-link logout-link">
                            <span class="nav-icon">🚪</span>
                            <span class="nav-text">Выйти</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<!-- Стили навигации -->
<style>
/* Основные стили навигации */
.main-navigation {
    position: sticky;
    top: 0;
    z-index: 1000;
    background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    backdrop-filter: blur(10px);
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.nav-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 70px;
}

/* Бренд */
.nav-brand {
    display: flex;
    align-items: center;
}

.brand-link {
    display: flex;
    align-items: center;
    text-decoration: none;
    color: white;
    font-weight: 700;
    font-size: 1.4em;
    transition: all 0.3s ease;
}

.brand-link:hover {
    transform: scale(1.05);
    text-shadow: 0 0 10px rgba(255, 255, 255, 0.3);
}

.brand-icon {
    font-size: 1.5em;
    margin-right: 10px;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

.brand-text {
    font-weight: 800;
    letter-spacing: -0.5px;
}

/* Мобильное меню */
.mobile-menu-toggle {
    display: none;
    flex-direction: column;
    background: none;
    border: none;
    cursor: pointer;
    padding: 8px;
    border-radius: 8px;
    transition: all 0.3s ease;
}

.mobile-menu-toggle:hover {
    background: rgba(255, 255, 255, 0.1);
}

.hamburger-line {
    width: 25px;
    height: 3px;
    background: white;
    margin: 3px 0;
    transition: all 0.3s ease;
    border-radius: 2px;
}

.mobile-menu-toggle.active .hamburger-line:nth-child(1) {
    transform: rotate(45deg) translate(6px, 6px);
}

.mobile-menu-toggle.active .hamburger-line:nth-child(2) {
    opacity: 0;
}

.mobile-menu-toggle.active .hamburger-line:nth-child(3) {
    transform: rotate(-45deg) translate(6px, -6px);
}

/* Навигационное меню */
.nav-menu {
    display: flex;
    align-items: center;
}

.nav-list {
    display: flex;
    list-style: none;
    margin: 0;
    padding: 0;
    align-items: center;
    gap: 5px;
}

.nav-item {
    position: relative;
}

.nav-link {
    display: flex;
    align-items: center;
    padding: 12px 16px;
    color: white;
    text-decoration: none;
    border-radius: 12px;
    transition: all 0.3s ease;
    font-weight: 600;
    position: relative;
    overflow: hidden;
}

.nav-link::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    transition: left 0.5s ease;
}

.nav-link:hover::before {
    left: 100%;
}

.nav-link:hover {
    background: rgba(255, 255, 255, 0.15);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
}

.nav-link.active {
    background: rgba(255, 255, 255, 0.2);
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
}

.nav-icon {
    font-size: 1.2em;
    margin-right: 8px;
    transition: transform 0.3s ease;
}

.nav-link:hover .nav-icon {
    transform: scale(1.2);
}

.nav-text {
    font-size: 0.95em;
}

/* Dropdown меню */
.dropdown {
    position: relative;
}

.dropdown-toggle {
    cursor: pointer;
}

.dropdown-arrow {
    margin-left: 5px;
    font-size: 0.8em;
    transition: transform 0.3s ease;
}

.dropdown:hover .dropdown-arrow {
    transform: rotate(180deg);
}

.dropdown-menu {
    position: absolute;
    top: 100%;
    left: 0;
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
    min-width: 200px;
    opacity: 0;
    visibility: hidden;
    transform: translateY(-10px);
    transition: all 0.3s ease;
    z-index: 1001;
    border: 1px solid rgba(0, 0, 0, 0.05);
}

.dropdown:hover .dropdown-menu {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.dropdown-link {
    display: block;
    padding: 12px 16px;
    color: #333;
    text-decoration: none;
    transition: all 0.3s ease;
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
}

.dropdown-link:last-child {
    border-bottom: none;
}

.dropdown-link:hover {
    background: #f8f9fa;
    color: #4CAF50;
    padding-left: 20px;
}

/* Информация о пользователе */
.user-info {
    margin-left: 20px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 20px;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.user-profile {
    display: flex;
    align-items: center;
    color: white;
}

.user-avatar {
    font-size: 1.2em;
    margin-right: 8px;
}

.user-name {
    font-weight: 600;
    margin-right: 8px;
}

.user-role {
    font-size: 0.8em;
    opacity: 0.8;
    background: rgba(255, 255, 255, 0.2);
    padding: 2px 8px;
    border-radius: 10px;
}

/* Ссылка выхода */
.logout-link {
    background: rgba(220, 53, 69, 0.2);
    border: 1px solid rgba(220, 53, 69, 0.3);
}

.logout-link:hover {
    background: rgba(220, 53, 69, 0.3);
    border-color: rgba(220, 53, 69, 0.5);
}

/* Адаптивность */
@media (max-width: 768px) {
    .mobile-menu-toggle {
        display: flex;
    }
    
    .nav-menu {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        border-radius: 0 0 20px 20px;
        transform: translateY(-100%);
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }
    
    .nav-menu.active {
        transform: translateY(0);
        opacity: 1;
        visibility: visible;
    }
    
    .nav-list {
        flex-direction: column;
        width: 100%;
        padding: 20px;
        gap: 10px;
    }
    
    .nav-item {
        width: 100%;
    }
    
    .nav-link {
        width: 100%;
        justify-content: flex-start;
        padding: 15px 20px;
        border-radius: 15px;
    }
    
    .user-info {
        margin-left: 0;
        margin-top: 10px;
        width: 100%;
        justify-content: center;
    }
    
    .dropdown-menu {
        position: static;
        opacity: 1;
        visibility: visible;
        transform: none;
        box-shadow: none;
        background: rgba(255, 255, 255, 0.1);
        margin-top: 10px;
        border-radius: 10px;
    }
    
    .dropdown-link {
        color: white;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .dropdown-link:hover {
        background: rgba(255, 255, 255, 0.1);
        color: white;
    }
}

@media (max-width: 480px) {
    .nav-container {
        padding: 0 15px;
        height: 60px;
    }
    
    .brand-text {
        font-size: 0.9em;
    }
    
    .nav-link {
        padding: 12px 15px;
        font-size: 0.9em;
    }
    
    .user-name {
        font-size: 0.9em;
    }
}
</style>

<!-- JavaScript для мобильного меню -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const mobileToggle = document.getElementById('mobileMenuToggle');
    const navMenu = document.getElementById('navMenu');
    
    if (mobileToggle && navMenu) {
        mobileToggle.addEventListener('click', function() {
            mobileToggle.classList.toggle('active');
            navMenu.classList.toggle('active');
        });
        
        // Закрытие меню при клике вне его
        document.addEventListener('click', function(event) {
            if (!mobileToggle.contains(event.target) && !navMenu.contains(event.target)) {
                mobileToggle.classList.remove('active');
                navMenu.classList.remove('active');
            }
        });
        
        // Закрытие меню при изменении размера экрана
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                mobileToggle.classList.remove('active');
                navMenu.classList.remove('active');
            }
        });
    }
    
    // Плавная прокрутка для якорных ссылок
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
});
</script>
