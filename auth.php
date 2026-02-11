<?php
require_once 'config.php';

session_start();

// Функция для проверки правил пароля
function validatePassword($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = "Пароль должен содержать минимум 8 символов";
    }
    
    if (!preg_match('/[A-Za-z]/', $password)) {
        $errors[] = "Пароль должен содержать хотя бы одну букву";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Пароль должен содержать хотя бы одну цифру";
    }
    
    if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
        $errors[] = "Пароль должен содержать хотя бы один специальный символ";
    }
    
    return $errors;
}

// Функция для хеширования пароля
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// Функция для проверки пароля
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Функция для проверки блокировки пользователя
function isUserBlocked($username) {
    $conn = connectToDatabase();
    $stmt = $conn->prepare("SELECT FailedLoginCount, Status FROM users WHERE Username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $conn->close();
        return $row['Status'] === 'blocked' || $row['FailedLoginCount'] >= 5;
    }
    
    $conn->close();
    return false;
}

// Функция для аутентификации пользователя
function authenticateUser($username, $password) {
    $conn = connectToDatabase();
    
    // Проверяем блокировку
    if (isUserBlocked($username)) {
        $conn->close();
        return ['success' => false, 'message' => 'Аккаунт заблокирован из-за множественных неудачных попыток входа'];
    }
    
    $stmt = $conn->prepare("SELECT UserID, Username, PasswordHash, Role, Status FROM users WHERE Username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        if ($row['Status'] === 'blocked') {
            $conn->close();
            return ['success' => false, 'message' => 'Аккаунт заблокирован'];
        }
        
        if (verifyPassword($password, $row['PasswordHash'])) {
            // Успешный вход - сбрасываем счетчик неудачных попыток
            $stmt = $conn->prepare("UPDATE users SET FailedLoginCount = 0 WHERE Username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            
            // Сохраняем данные пользователя в сессии
            $_SESSION['user_id'] = $row['UserID'];
            $_SESSION['username'] = $row['Username'];
            $_SESSION['role'] = $row['Role'];
            $_SESSION['logged_in'] = true;
            
            $conn->close();
            return ['success' => true, 'message' => 'Вход выполнен успешно', 'role' => $row['Role']];
        } else {
            // Неудачная попытка входа
            $stmt = $conn->prepare("UPDATE users SET FailedLoginCount = FailedLoginCount + 1, LastFailedLoginAt = NOW() WHERE Username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            
            $conn->close();
            return ['success' => false, 'message' => 'Неверный пароль'];
        }
    } else {
        $conn->close();
        return ['success' => false, 'message' => 'Пользователь не найден'];
    }
}

// Функция для регистрации пользователя
function registerUser($username, $password, $role = 'user', $phoneNumber = '') {
    $conn = connectToDatabase();
    
    // Проверяем правила пароля
    $passwordErrors = validatePassword($password);
    if (!empty($passwordErrors)) {
        $conn->close();
        return ['success' => false, 'message' => implode(', ', $passwordErrors)];
    }
    
    try {
        // Проверяем, существует ли пользователь
        $stmt = $conn->prepare("SELECT UserID FROM users WHERE Username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $conn->close();
            return ['success' => false, 'message' => 'Пользователь уже существует'];
        }
        
        // Хешируем пароль в PHP
        $passwordHash = hashPassword($password);
        
        // Нормализуем номер телефона, если он указан
        $normalizedPhone = '';
        if (!empty($phoneNumber)) {
            $normalizedPhone = preg_replace('/[^0-9+]/', '', trim($phoneNumber));
            if (!empty($normalizedPhone) && $normalizedPhone[0] !== '+') {
                if (preg_match('/^[78]/', $normalizedPhone)) {
                    $normalizedPhone = '+7' . substr($normalizedPhone, 1);
                } else {
                    $normalizedPhone = '+7' . $normalizedPhone;
                }
            }
        }
        
        // Вставляем пользователя напрямую
        $stmt = $conn->prepare("INSERT INTO users (Username, PasswordHash, Role, Status, PhoneNumber, PasswordCreatedAt, CreatedAt, UpdatedAt) VALUES (?, ?, ?, 'active', ?, NOW(), NOW(), NOW())");
        $stmt->bind_param("ssss", $username, $passwordHash, $role, $normalizedPhone);
        
        if ($stmt->execute()) {
            $conn->close();
            return ['success' => true, 'message' => 'Пользователь зарегистрирован успешно'];
        } else {
            $conn->close();
            return ['success' => false, 'message' => 'Ошибка при создании пользователя'];
        }
        
    } catch (Exception $e) {
        $conn->close();
        return ['success' => false, 'message' => 'Ошибка регистрации: ' . $e->getMessage()];
    }
}

// Функция для проверки авторизации
function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

// Функция для проверки роли
function hasRole($role) {
    return isLoggedIn() && $_SESSION['role'] === $role;
}

// Функция для выхода
function logout() {
    // Очищаем выбранное предприятие
    unset($_SESSION['company_id']);
    unset($_SESSION['selected_company_id']);
    session_destroy();
    return ['success' => true, 'message' => 'Выход выполнен успешно'];
}

// Функция для получения информации о пользователе
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'role' => $_SESSION['role']
    ];
}

// Функция для перенаправления неавторизованных пользователей
function requireAuth() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

// Функция для перенаправления пользователей без нужной роли
function requireRole($role) {
    requireAuth();
    if (!hasRole($role)) {
        header('Location: unauthorized.php');
        exit;
    }
}

// Функция для получения списка предприятий
function getCompanies() {
    $conn = connectToDatabase();
    $companies = [];
    
    $result = $conn->query("SELECT CompanyID, CompanyName FROM companies ORDER BY CompanyName");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $companies[] = $row;
        }
    }
    
    $conn->close();
    return $companies;
}

// Функция для сохранения выбранного предприятия в сессии
function setSelectedCompany($companyId) {
    $_SESSION['company_id'] = $companyId;
}

// Функция для получения выбранного предприятия из сессии
function getSelectedCompany() {
    return isset($_SESSION['company_id']) ? $_SESSION['company_id'] : null;
}

// Функция для получения названия выбранного предприятия
function getSelectedCompanyName() {
    $companyId = getSelectedCompany();
    if (!$companyId) {
        return '';
    }
    
    $conn = connectToDatabase();
    $stmt = $conn->prepare("SELECT CompanyName FROM companies WHERE CompanyID = ?");
    $stmt->bind_param("i", $companyId);
    $stmt->execute();
    $result = $stmt->get_result();
    $companyName = '';
    if ($row = $result->fetch_assoc()) {
        $companyName = $row['CompanyName'];
    }
    $stmt->close();
    $conn->close();
    
    return $companyName;
}
?>
