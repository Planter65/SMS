<?php
require_once 'auth.php';

requireAuth();

// Администратор не выбирает предприятие — сразу в админку
if (hasRole('admin')) {
    header('Location: admin.php');
    exit;
}

$user = getCurrentUser();
$userCompanies = getCompaniesForUser($user['id']);

// Выбор предприятия (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['company_id'])) {
    $companyId = intval($_POST['company_id']);
    $allowedIds = array_column($userCompanies, 'CompanyID');
    if ($companyId > 0 && in_array($companyId, $allowedIds, true)) {
        setSelectedCompany($companyId);
        header('Location: user.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Выбор предприятия</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #667eea;
            background-image: url('fon.gif');
            background-position: center center;
            background-size: cover;
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 500px;
            width: 100%;
        }
        .header {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 { font-size: 1.8em; margin-bottom: 8px; }
        .header p { font-size: 1em; opacity: 0.9; }
        .main-content {
            padding: 40px;
            min-height: 200px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        .no-companies-msg {
            color: #721c24;
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 24px;
            border-radius: 12px;
            font-size: 1.1em;
            line-height: 1.5;
        }
        .company-list { width: 100%; }
        .company-list form {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .btn-company {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
            padding: 16px 24px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
        }
        .btn-company:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(76, 175, 80, 0.3);
        }
        .logout-link {
            display: inline-block;
            margin-top: 20px;
            color: #666;
            text-decoration: none;
            font-size: 14px;
        }
        .logout-link:hover { color: #333; text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Выбор предприятия</h1>
            <p>Выберите шапку предприятия для работы</p>
        </div>
        <div class="main-content">
            <?php if (empty($userCompanies)): ?>
                <p class="no-companies-msg">Извините, вы не привязаны к предприятиям. Обратитесь к администратору.</p>
            <?php else: ?>
                <div class="company-list">
                    <form method="POST">
                        <?php foreach ($userCompanies as $company): ?>
                            <button type="submit" name="company_id" value="<?php echo (int)$company['CompanyID']; ?>" class="btn-company">
                                <?php echo htmlspecialchars($company['CompanyName']); ?>
                            </button>
                        <?php endforeach; ?>
                    </form>
                </div>
            <?php endif; ?>
            <a href="logout.php" class="logout-link">Выйти</a>
        </div>
    </div>
</body>
</html>
