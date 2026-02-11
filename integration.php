<?php
require_once 'auth.php';
requireRole('admin');

/**
 * AJAX-проверка интеграционной строки.
 * Возвращает результат по критериям + наличие совпадений в БД.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'validate_integration') {
    header('Content-Type: application/json; charset=utf-8');

    $payload = trim($_POST['payload'] ?? '');
    $criteria = [
        'length' => mb_strlen($payload) >= 12,
        'upper' => preg_match('/[A-ZА-Я]/u', $payload) === 1,
        'lower' => preg_match('/[a-zа-я]/u', $payload) === 1,
        'digit' => preg_match('/\d/', $payload) === 1,
        'special' => preg_match('/[^A-Za-zА-Яа-я0-9]/u', $payload) === 1,
    ];

    $dbMatch = false;
    $matchDetails = null;

    if ($payload !== '') {
        $conn = connectToDatabase();

        $sources = [
            [
                'sql' => "SELECT Username AS label FROM users WHERE Username = ? LIMIT 1",
                'type' => 'Пользователь',
                'hint' => 'users'
            ],
            [
                'sql' => "SELECT FullName AS label FROM recipients WHERE FullName = ? LIMIT 1",
                'type' => 'Получатель',
                'hint' => 'recipients'
            ],
            [
                'sql' => "SELECT GroupName AS label FROM groups WHERE GroupName = ? LIMIT 1",
                'type' => 'Группа',
                'hint' => 'groups'
            ],
        ];

        foreach ($sources as $source) {
            $stmt = $conn->prepare($source['sql']);
            $stmt->bind_param('s', $payload);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($row && isset($row['label'])) {
                $dbMatch = true;
                $matchDetails = [
                    'type' => $source['type'],
                    'value' => $row['label'],
                    'table' => $source['hint'],
                ];
                break;
            }
        }

        $conn->close();
    }

    $criteria['db_match'] = $dbMatch;

    echo json_encode([
        'success' => true,
        'criteria' => $criteria,
        'match' => $matchDetails,
        'message' => $dbMatch
            ? 'Информация найдена в базе данных'
            : 'Совпадений в базе данных не найдено'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Интеграционная проверка — СМС система</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }

        .integration-wrapper {
            max-width: 1100px;
            margin: 30px auto;
            padding: 20px;
        }

        .integration-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 35px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }

        .integration-card h2 {
            color: #4CAF50;
            margin-bottom: 10px;
        }

        .integration-card p {
            color: #555;
            margin-bottom: 20px;
        }

        textarea {
            width: 100%;
            min-height: 140px;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 16px;
            resize: vertical;
            transition: all 0.3s ease;
        }

        textarea:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.15);
        }

        .criteria-board {
            margin-top: 30px;
            background: #f8f9fa;
            border-radius: 15px;
            padding: 20px;
        }

        .criteria-board h3 {
            margin-bottom: 15px;
            color: #333;
        }

        .criteria-board ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .criteria-board li {
            display: flex;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
            font-weight: 500;
        }

        .criteria-board li:last-child {
            border-bottom: none;
        }

        .criteria-marker {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            border: 2px solid #ccc;
            margin-right: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .criteria-pass .criteria-marker {
            border-color: #28a745;
            background: rgba(40, 167, 69, 0.15);
            color: #28a745;
            content: '✔';
        }

        .criteria-fail .criteria-marker {
            border-color: #dc3545;
            background: rgba(220, 53, 69, 0.15);
            color: #dc3545;
        }

        .match-result {
            margin-top: 25px;
            border-radius: 12px;
            padding: 18px;
            background: rgba(76, 175, 80, 0.1);
            color: #2e7d32;
            display: none;
        }

        .match-result.error {
            background: rgba(220, 53, 69, 0.1);
            color: #b71c1c;
        }

        .status-message {
            margin-top: 20px;
            display: none;
        }

        .integration-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <?php include 'navigation.php'; ?>

    <div class="integration-wrapper">
        <div class="integration-card">
            <h2>🔗 Интеграционная проверка</h2>
            <p>
                Введите служебную строку интеграции (например, идентификатор пользователя, получателя или группы),
                чтобы проверить её соответствие критериям безопасности и наличие в базе данных.
            </p>

            <div class="form-group">
                <label for="integrationPayload">Интеграционная строка</label>
                <textarea id="integrationPayload" placeholder="Например: AdminUser#2025!"></textarea>
            </div>

            <div class="integration-actions">
                <button class="btn" id="checkIntegrationBtn">
                    <i class="fas fa-shield-alt"></i> Проверить интеграцию
                </button>
                <button class="btn btn-secondary" id="loadJsonBtn" type="button">
                    <i class="fas fa-file-upload"></i> Загрузить JSON
                </button>
                <input type="file" id="jsonFileInput" accept=".json,application/json" hidden>
            </div>

            <div class="status-message" id="integrationStatus"></div>

            <div class="criteria-board">
                <h3>Критерии проверки</h3>
                <ul id="integrationCriteriaList">
                    <li data-criterion="length">
                        <span class="criteria-marker">•</span> Не менее 12 символов
                    </li>
                    <li data-criterion="upper">
                        <span class="criteria-marker">•</span> Есть заглавная буква
                    </li>
                    <li data-criterion="lower">
                        <span class="criteria-marker">•</span> Есть строчная буква
                    </li>
                    <li data-criterion="digit">
                        <span class="criteria-marker">•</span> Есть цифра
                    </li>
                    <li data-criterion="special">
                        <span class="criteria-marker">•</span> Есть спецсимвол
                    </li>
                    <li data-criterion="db_match">
                        <span class="criteria-marker">•</span> Совпадает с записью в БД
                    </li>
                </ul>
            </div>

            <div class="match-result" id="matchResult"></div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js" crossorigin="anonymous"></script>
    <script>
        const payloadInput = document.getElementById('integrationPayload');
        const checkBtn = document.getElementById('checkIntegrationBtn');
        const criteriaList = document.getElementById('integrationCriteriaList');
        const statusBlock = document.getElementById('integrationStatus');
        const matchResultBlock = document.getElementById('matchResult');
        const loadJsonBtn = document.getElementById('loadJsonBtn');
        const jsonFileInput = document.getElementById('jsonFileInput');

        const localCriteria = {
            length: value => value.length >= 12,
            upper: value => /[A-ZА-Я]/.test(value),
            lower: value => /[a-zа-я]/.test(value),
            digit: value => /\d/.test(value),
            special: value => /[^A-Za-zА-Яа-я0-9]/.test(value)
        };

        const refreshCriteriaBoard = (results = {}) => {
            criteriaList.querySelectorAll('li').forEach(item => {
                const key = item.dataset.criterion;
                const passed = results[key];
                item.classList.remove('criteria-pass', 'criteria-fail');
                if (passed === undefined) {
                    return;
                }
                item.classList.add(passed ? 'criteria-pass' : 'criteria-fail');
                const marker = item.querySelector('.criteria-marker');
                if (marker) {
                    marker.textContent = passed ? '✔' : '✖';
                }
            });
        };

        const showStatus = (message, isSuccess) => {
            if (!statusBlock) {
                return;
            }
            statusBlock.textContent = message;
            statusBlock.className = 'status-message ' + (isSuccess ? 'status-success' : 'status-error');
            statusBlock.style.display = 'block';
        };

        const toggleCheckLoading = (isLoading) => {
            if (!checkBtn) {
                return;
            }
            checkBtn.disabled = isLoading;
            checkBtn.innerHTML = isLoading
                ? '<i class="fas fa-spinner fa-spin"></i> Проверка...'
                : '<i class="fas fa-shield-alt"></i> Проверить интеграцию';
        };

        const validateIntegration = (payload) => {
            const trimmed = (payload || '').trim();
            if (!trimmed) {
                showStatus('Введите интеграционную строку для проверки', false);
                return;
            }

            toggleCheckLoading(true);

            const formData = new FormData();
            formData.append('action', 'validate_integration');
            formData.append('payload', trimmed);

            fetch('integration.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        showStatus('Ошибка проверки. Попробуйте снова.', false);
                        return;
                    }

                    refreshCriteriaBoard(data.criteria || {});
                    showStatus(data.message || '', Boolean(data.criteria?.db_match));

                    if (data.match) {
                        matchResultBlock.textContent = `Найдена запись: ${data.match.type} "${data.match.value}" (таблица ${data.match.table})`;
                        matchResultBlock.classList.remove('error');
                        matchResultBlock.style.display = 'block';
                    } else {
                        matchResultBlock.textContent = 'Запись не найдена в пользователях, получателях или группах.';
                        matchResultBlock.classList.add('error');
                        matchResultBlock.style.display = 'block';
                    }
                })
                .catch(() => {
                    showStatus('Не удалось выполнить проверку. Проверьте подключение.', false);
                })
                .finally(() => {
                    toggleCheckLoading(false);
                });
        };

        payloadInput.addEventListener('input', (event) => {
            const value = event.target.value;
            const current = {};
            Object.keys(localCriteria).forEach(key => {
                current[key] = localCriteria[key](value);
            });
            refreshCriteriaBoard(current);
            statusBlock.style.display = 'none';
            matchResultBlock.style.display = 'none';
        });

        checkBtn.addEventListener('click', () => {
            validateIntegration(payloadInput.value);
        });

        if (loadJsonBtn && jsonFileInput) {
            loadJsonBtn.addEventListener('click', () => {
                jsonFileInput.click();
            });

            jsonFileInput.addEventListener('change', (event) => {
                const file = event.target.files && event.target.files[0];
                if (!file) {
                    return;
                }

                if (file.type && file.type !== 'application/json') {
                    showStatus('Пожалуйста, выберите JSON-файл.', false);
                    jsonFileInput.value = '';
                    return;
                }

                const reader = new FileReader();
                reader.onload = (loadEvent) => {
                    try {
                        const data = JSON.parse(loadEvent.target.result);
                        const rawValue = data?.value;
                        const stringValue = rawValue !== undefined ? String(rawValue).trim() : '';

                        if (!stringValue) {
                            showStatus('Поле "value" отсутствует или пустое.', false);
                            return;
                        }

                        payloadInput.value = stringValue;
                        payloadInput.dispatchEvent(new Event('input'));
                        validateIntegration(stringValue);
                    } catch (error) {
                        showStatus('Не удалось прочитать содержимое JSON-файла.', false);
                    } finally {
                        jsonFileInput.value = '';
                    }
                };

                reader.onerror = () => {
                    showStatus('Ошибка чтения файла. Попробуйте другой файл.', false);
                    jsonFileInput.value = '';
                };

                reader.readAsText(file, 'UTF-8');
            });
        }
    </script>
</body>
</html>

