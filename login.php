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
}

$error = '';
$success = '';

// Обработка формы входа (теперь только для проверки капчи)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'login') {
        // Форма входа теперь обрабатывается через JavaScript и капчу
        // Этот блок больше не используется напрямую
    }
}

// Обработка капчи-пазла
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_captcha') {
    $puzzle_order = $_POST['puzzle_order'] ?? '';
    $correct_order = $_POST['correct_order'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Проверяем, что пользователь собрал пазл
    if (empty($puzzle_order) || empty($correct_order) || empty($username) || empty($password)) {
        $error = 'Заполните все поля и соберите пазл';
    } else {
        // Проверяем правильность собранного пазла
        $user_order = explode(',', $puzzle_order);
        $correct_order_array = explode(',', $correct_order);
        
        // Отладочная информация
        error_log("User order: " . print_r($user_order, true));
        error_log("Correct order: " . print_r($correct_order_array, true));
        
        // Сравниваем порядок
        if ($user_order === $correct_order_array) {
            // Пазл собран правильно, выполняем аутентификацию
            $result = authenticateUser($username, $password);
            if ($result['success']) {
                // Перенаправляем на соответствующую страницу
                if ($result['role'] === 'admin') {
                    header('Location: admin.php');
                } else {
                    header('Location: user.php');
                }
                exit;
            } else {
                $error = $result['message'];
            }
        } else {
            $error = 'Пазл собран неправильно. Попробуйте еще раз.';
        }
    }
}

// Генерация капчи-пазла
function generateCaptcha() {
    // Создаем пазл из 4 фрагментов (2x2)
    $puzzle_pieces = [
        '1.png', '2.png', '3.png', '4.png'
    ];
    
    // Фиксированный правильный порядок: 1,2,3,4 (слева направо, сверху вниз)
    $correct_order = ['1.png', '2.png', '3.png', '4.png'];
    
    // Сохраняем правильный порядок в сессии
    $_SESSION['captcha_answer'] = $correct_order;
    
    return [
        'pieces' => $puzzle_pieces,
        'correct_order' => $correct_order
    ];
}

// Если это запрос на показ капчи
if (isset($_GET['show_captcha'])) {
    $captcha_data = generateCaptcha();
    $_SESSION['pending_username'] = $_POST['username'] ?? '';
    $_SESSION['pending_password'] = $_POST['password'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход в систему СМС информирования</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            max-width: 1000px;
            width: 100%;
        }

        .header {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
            padding: 40px;
            text-align: center;
        }

        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }

        .header p {
            font-size: 1.1em;
            opacity: 0.9;
        }

        .main-content {
            display: grid;
            grid-template-columns: 1fr;
            min-height: 500px;
        }

        .info-section {
            background: #f8f9fa;
            padding: 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .info-section h2 {
            color: #333;
            margin-bottom: 20px;
            font-size: 1.8em;
        }

        .info-section p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 15px;
        }

        .features {
            list-style: none;
            margin-top: 20px;
        }

        .features li {
            padding: 8px 0;
            color: #555;
            display: flex;
            align-items: center;
        }

        .features li::before {
            content: "✓";
            color: #4CAF50;
            font-weight: bold;
            margin-right: 10px;
        }

        .auth-section {
            padding: 40px;
        }

        .tabs {
            display: flex;
            margin-bottom: 30px;
            border-bottom: 2px solid #e0e0e0;
        }

        .tab {
            padding: 15px 25px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            color: #666;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
        }

        .tab.active {
            color: #4CAF50;
            border-bottom-color: #4CAF50;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }

        input[type="text"], 
        input[type="password"] {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
        }

        input[type="text"]:focus, 
        input[type="password"]:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }

        .btn {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(76, 175, 80, 0.3);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
        }

        .btn-secondary:hover {
            box-shadow: 0 8px 25px rgba(108, 117, 125, 0.3);
        }

        .status-message {
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            display: none;
        }

        .status-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .status-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .password-rules {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
            font-size: 14px;
            color: #666;
        }

        .password-rules h4 {
            margin-bottom: 10px;
            color: #333;
        }

        .password-rules ul {
            margin: 0;
            padding-left: 20px;
        }

        .password-rules li {
            margin-bottom: 5px;
        }

        /* Стили для модального окна капчи */
        .captcha-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .captcha-modal-content {
            background: white;
            border-radius: 20px;
            padding: 30px;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        }

        .captcha-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .captcha-header h3 {
            color: #333;
            margin-bottom: 10px;
            font-size: 1.5em;
        }

        .captcha-header p {
            color: #666;
            font-size: 1.1em;
        }

        .captcha-question {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            text-align: center;
        }

        .captcha-question h4 {
            color: #333;
            font-size: 1.3em;
            margin: 0;
        }

        .puzzle-container {
            display: flex;
            gap: 30px;
            justify-content: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .puzzle-area {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 5px;
            width: 200px;
            height: 200px;
            border: 3px dashed #ccc;
            border-radius: 10px;
            padding: 10px;
            background: #f8f9fa;
        }

        .puzzle-slot {
            width: 90px;
            height: 90px;
            border: 2px solid #ddd;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            transition: all 0.3s ease;
            position: relative;
            min-height: 90px;
        }

        .puzzle-slot:empty::after {
            content: attr(data-placeholder);
            color: #999;
            font-size: 12px;
            text-align: center;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .puzzle-slot.drag-over {
            border-color: #4CAF50;
            background: rgba(76, 175, 80, 0.1);
        }

        .puzzle-slot img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 6px;
        }

        .puzzle-pieces {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            width: 200px;
        }

        .puzzle-piece {
            width: 90px;
            height: 90px;
            cursor: grab;
            border: 3px solid #4CAF50;
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.3s ease;
            background: white;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
        }

        .puzzle-piece:hover {
            transform: scale(1.05);
            box-shadow: 0 8px 25px rgba(76, 175, 80, 0.3);
        }

        .puzzle-piece:active {
            cursor: grabbing;
            transform: scale(1.1);
        }

        .puzzle-piece.dragging {
            opacity: 0.5;
            transform: rotate(5deg);
        }

        .puzzle-piece img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .puzzle-slot .puzzle-piece {
            cursor: pointer;
            border-color: #28a745;
            position: relative;
        }

        .puzzle-slot .puzzle-piece:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }

        .puzzle-slot .puzzle-piece::after {
            content: "×";
            position: absolute;
            top: -8px;
            right: -8px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: bold;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .puzzle-slot .puzzle-piece:hover::after {
            opacity: 1;
        }

        .puzzle-instructions {
            text-align: center;
            margin-bottom: 20px;
            color: #666;
            font-size: 1.1em;
        }

        .captcha-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
        }

        .captcha-actions .btn {
            width: auto;
            padding: 12px 25px;
        }

        /* Стили для модального окна успешного входа */
        .success-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }

        .success-modal-content {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 400px;
            width: 90%;
            text-align: center;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            animation: successModalAppear 0.5s ease-out;
        }

        @keyframes successModalAppear {
            from {
                opacity: 0;
                transform: scale(0.8) translateY(-50px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .success-icon {
            font-size: 4em;
            color: #4CAF50;
            margin-bottom: 20px;
            animation: successIconBounce 0.6s ease-out;
        }

        @keyframes successIconBounce {
            0% {
                transform: scale(0);
            }
            50% {
                transform: scale(1.2);
            }
            100% {
                transform: scale(1);
            }
        }

        .success-title {
            color: #333;
            font-size: 1.8em;
            margin-bottom: 15px;
            font-weight: 600;
        }

        .success-message {
            color: #666;
            font-size: 1.1em;
            margin-bottom: 25px;
        }

        .success-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            color: #4CAF50;
            font-weight: 500;
        }

        .loading-spinner {
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #4CAF50;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .main-content {
                grid-template-columns: 1fr;
            }
            
            .header h1 {
                font-size: 2em;
            }

            .puzzle-container {
                flex-direction: column;
                align-items: center;
                gap: 20px;
            }

            .puzzle-area, .puzzle-pieces {
                width: 180px;
            }

            .puzzle-slot, .puzzle-piece {
                width: 80px;
                height: 80px;
            }

            .captcha-modal-content {
                padding: 20px;
                margin: 20px;
            }

            .captcha-actions {
                flex-direction: column;
                gap: 10px;
            }

            .captcha-actions .btn {
                width: 100%;
            }
        }
    </style>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📱 Система СМС информирования</h1>
            <p>Автоматизация процесса отправки СМС-оповещений</p>
        </div>

        <div class="main-content">
            <!-- Информационная секция удалена по запросу -->

            <!-- Секция авторизации -->
            <div class="auth-section">
                <div class="tabs">
                    <button class="tab active" onclick="showTab('login')">Вход</button>
                </div>

                <!-- Форма входа -->
                <div class="tab-content active" id="loginTab">
                    <h3>Вход в систему</h3>
                    <form method="POST" id="loginForm">
                        <input type="hidden" name="action" value="login">
                        
                        <div class="form-group">
                            <label for="username">Имя пользователя:</label>
                            <input type="text" id="username" name="username" required>
                        </div>

                        <div class="form-group">
                            <label for="password">Пароль:</label>
                            <input type="password" id="password" name="password" required>
                        </div>

                        <button type="submit" class="btn">Войти</button>
                    </form>
                </div>

                <!-- Информация о регистрации у администратора -->
                <div class="password-rules" style="margin-top: 10px;">
                    <strong>Регистрация выполняется администратором.</strong> Обратитесь к администратору для добавления учетной записи.
                </div>

                <!-- Сообщения о статусе -->
                <?php if ($error): ?>
                    <div class="status-message status-error" style="display: block;">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="status-message status-success" style="display: block;">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Модальное окно капчи-пазла -->
    <div id="captchaModal" class="captcha-modal" style="display: none;">
        <div class="captcha-modal-content">
            <div class="captcha-header">
                <h3>🧩 Проверка безопасности</h3>
                <p>Соберите пазл, перетащив фрагменты в правильном порядке:</p>
            </div>
            
            <div class="puzzle-instructions">
                Перетащите фрагменты из правой области в левую, чтобы собрать полную картину.<br>
                <strong>Правильный порядок:</strong> 1, 2 (верхний ряд), 3, 4 (нижний ряд)
            </div>
            
            <div class="puzzle-container">
                <div class="puzzle-area" id="puzzleArea">
                    <div class="puzzle-slot" data-position="0" data-placeholder="1.png"></div>
                    <div class="puzzle-slot" data-position="1" data-placeholder="2.png"></div>
                    <div class="puzzle-slot" data-position="2" data-placeholder="3.png"></div>
                    <div class="puzzle-slot" data-position="3" data-placeholder="4.png"></div>
                </div>
                
                <div class="puzzle-pieces" id="puzzlePieces">
                    <!-- Фрагменты пазла будут загружены через JavaScript -->
                </div>
            </div>
            
            <div class="captcha-actions">
                <button type="button" class="btn btn-secondary" onclick="closeCaptcha()">Отмена</button>
                <button type="button" class="btn" onclick="verifyCaptcha()">Проверить</button>
                <button type="button" class="btn btn-secondary" onclick="shufflePuzzle()">Перемешать</button>
            </div>
        </div>
    </div>

    <!-- Модальное окно успешного входа -->
    <div id="successModal" class="success-modal" style="display: none;">
        <div class="success-modal-content">
            <div class="success-icon">✅</div>
            <div class="success-title">Успешный вход!</div>
            <div class="success-message">Добро пожаловать в систему СМС информирования</div>
            <div class="success-loading">
                <div class="loading-spinner"></div>
                <span>Перенаправление...</span>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            // Скрываем все вкладки
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Показываем нужную вкладку
            document.getElementById(tabName + 'Tab').classList.add('active');
            event.target.classList.add('active');
        }

        // Обработка формы входа
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const username = document.getElementById('username').value;
            const password = document.getElementById('password').value;
            
            if (!username || !password) {
                alert('Заполните все поля');
                return;
            }
            
            // Показываем капчу
            showCaptcha(username, password);
        });

        function showCaptcha(username, password) {
            // Генерируем случайную капчу-пазл
            const captchaData = generateRandomCaptcha();
            
            // Показываем модальное окно
            document.getElementById('captchaModal').style.display = 'flex';
            
            // Загружаем фрагменты пазла
            loadPuzzlePieces(captchaData.pieces, captchaData.correctOrder);
            
            // Сохраняем данные для проверки
            window.currentCaptcha = {
                correctOrder: captchaData.correctOrder,
                username: username,
                password: password
            };
            
            // Инициализируем drag and drop после небольшой задержки
            setTimeout(() => {
                initializeDragAndDrop();
            }, 100);
        }

        function generateRandomCaptcha() {
            const pieces = ['1.png', '2.png', '3.png', '4.png'];
            // Фиксированный правильный порядок: 1,2,3,4 (слева направо, сверху вниз)
            const correctOrder = ['1.png', '2.png', '3.png', '4.png'];
            
            return {
                pieces: pieces,
                correctOrder: correctOrder
            };
        }

        function loadPuzzlePieces(pieces, correctOrder) {
            const container = document.getElementById('puzzlePieces');
            container.innerHTML = '';
            
            // Перемешиваем фрагменты для отображения
            const shuffledPieces = [...pieces].sort(() => Math.random() - 0.5);
            
            shuffledPieces.forEach((piece, index) => {
                const pieceDiv = document.createElement('div');
                pieceDiv.className = 'puzzle-piece';
                pieceDiv.draggable = true;
                pieceDiv.dataset.image = piece;
                pieceDiv.dataset.originalIndex = index;
                
                const img = document.createElement('img');
                img.src = 'Фото для капчи/' + piece;
                img.alt = 'Фрагмент ' + piece;
                img.draggable = false;
                img.oncontextmenu = function(e) { e.preventDefault(); return false; };
                
                pieceDiv.appendChild(img);
                container.appendChild(pieceDiv);
                
                // Добавляем обработчики событий сразу после создания элемента
                addDragHandlers(pieceDiv);
            });
        }

        function initializeDragAndDrop() {
            const pieces = document.querySelectorAll('.puzzle-piece');
            const slots = document.querySelectorAll('.puzzle-slot');
            
            pieces.forEach(piece => {
                addDragHandlers(piece);
            });
            
            slots.forEach(slot => {
                slot.addEventListener('dragover', handleDragOver);
                slot.addEventListener('drop', handleDrop);
                slot.addEventListener('dragenter', handleDragEnter);
                slot.addEventListener('dragleave', handleDragLeave);
            });
        }

        function addDragHandlers(piece) {
            // Удаляем старые обработчики, если они есть
            piece.removeEventListener('dragstart', handleDragStart);
            piece.removeEventListener('dragend', handleDragEnd);
            
            // Добавляем новые обработчики
            piece.addEventListener('dragstart', handleDragStart);
            piece.addEventListener('dragend', handleDragEnd);
        }

        function removePieceFromSlot(piece) {
            const piecesContainer = document.getElementById('puzzlePieces');
            piecesContainer.appendChild(piece);
            addDragHandlers(piece);
            piece.removeEventListener('click', removePieceFromSlot);
            piece.title = '';
        }

        function handleDragStart(e) {
            console.log('Drag start:', e.target.dataset.image);
            e.target.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/html', e.target.outerHTML);
            e.dataTransfer.setData('text/plain', e.target.dataset.image);
        }

        function handleDragEnd(e) {
            e.target.classList.remove('dragging');
        }

        function handleDragOver(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            console.log('Drag over slot');
        }

        function handleDragEnter(e) {
            e.preventDefault();
            e.target.classList.add('drag-over');
        }

        function handleDragLeave(e) {
            e.target.classList.remove('drag-over');
        }

        function handleDrop(e) {
            e.preventDefault();
            e.target.classList.remove('drag-over');
            
            const imageName = e.dataTransfer.getData('text/plain');
            console.log('Drop event:', imageName, e.target.classList.contains('puzzle-slot'));
            
            const draggedElement = document.querySelector(`[data-image="${imageName}"].dragging`);
            console.log('Dragged element found:', !!draggedElement);
            
            if (draggedElement && e.target.classList.contains('puzzle-slot')) {
                console.log('Processing drop...');
                
                // Удаляем старый элемент из слота, если он есть
                const existingPiece = e.target.querySelector('.puzzle-piece');
                if (existingPiece) {
                    // Возвращаем старый элемент в область фрагментов
                    const piecesContainer = document.getElementById('puzzlePieces');
                    piecesContainer.appendChild(existingPiece);
                    // Добавляем обработчики для возвращенного элемента
                    addDragHandlers(existingPiece);
                }
                
                // Создаем новый элемент для слота
                const newPiece = document.createElement('div');
                newPiece.className = 'puzzle-piece';
                newPiece.draggable = true;
                newPiece.dataset.image = imageName;
                newPiece.title = 'Кликните, чтобы убрать из пазла';
                
                const img = document.createElement('img');
                img.src = 'Фото для капчи/' + imageName;
                img.alt = 'Фрагмент ' + imageName;
                img.draggable = false;
                img.oncontextmenu = function(e) { e.preventDefault(); return false; };
                
                newPiece.appendChild(img);
                e.target.appendChild(newPiece);
                
                // Удаляем оригинальный элемент
                draggedElement.remove();
                
                // Добавляем обработчики событий для нового элемента
                addDragHandlers(newPiece);
                
                // Добавляем возможность удаления по клику
                newPiece.addEventListener('click', function() {
                    removePieceFromSlot(newPiece);
                });
                
                console.log('Drop completed successfully');
            }
        }

        function shufflePuzzle() {
            // Очищаем все слоты
            document.querySelectorAll('.puzzle-slot').forEach(slot => {
                const piece = slot.querySelector('.puzzle-piece');
                if (piece) {
                    const piecesContainer = document.getElementById('puzzlePieces');
                    piecesContainer.appendChild(piece);
                    addDragHandlers(piece);
                    piece.removeEventListener('click', removePieceFromSlot);
                    piece.title = '';
                }
            });
            
            // Перемешиваем фрагменты в области фрагментов
            const pieces = Array.from(document.querySelectorAll('.puzzle-piece'));
            pieces.forEach(piece => {
                const randomIndex = Math.floor(Math.random() * pieces.length);
                piece.style.order = randomIndex;
            });
        }

        function verifyCaptcha() {
            const slots = document.querySelectorAll('.puzzle-slot');
            const currentOrder = [];
            
            slots.forEach(slot => {
                const piece = slot.querySelector('.puzzle-piece');
                if (piece) {
                    currentOrder.push(piece.dataset.image);
                } else {
                    currentOrder.push('');
                }
            });
            
            // Проверяем, что все слоты заполнены
            if (currentOrder.some(image => image === '')) {
                alert('Соберите пазл полностью');
                return;
            }
            
            console.log('Current order:', currentOrder);
            console.log('Correct order:', window.currentCaptcha.correctOrder);
            
            // Показываем уведомление об успешном входе
            showSuccessMessage();
            
            // Отправляем данные на сервер для проверки с небольшой задержкой
            setTimeout(() => {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="verify_captcha">
                    <input type="hidden" name="puzzle_order" value="${currentOrder.join(',')}">
                    <input type="hidden" name="correct_order" value="${window.currentCaptcha.correctOrder.join(',')}">
                    <input type="hidden" name="username" value="${window.currentCaptcha.username}">
                    <input type="hidden" name="password" value="${window.currentCaptcha.password}">
                `;
                document.body.appendChild(form);
                form.submit();
            }, 2000); // Задержка 2 секунды для показа уведомления
        }

        function closeCaptcha() {
            document.getElementById('captchaModal').style.display = 'none';
            window.selectedImage = null;
            window.currentCaptcha = null;
        }

        function showSuccessMessage() {
            // Закрываем модальное окно капчи
            document.getElementById('captchaModal').style.display = 'none';
            
            // Показываем модальное окно успешного входа
            document.getElementById('successModal').style.display = 'flex';
        }
    </script>
</body>
</html>
