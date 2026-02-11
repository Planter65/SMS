<?php
/**
 * Модуль для работы с различными SMS-провайдерами
 * Поддерживает: SMS.ru, SMSC.ru, Twilio, эмуляцию
 */

require_once 'config.php';

/**
 * Создаем таблицу настроек SMS, если её нет
 */
function ensureSmsSettingsTable($conn) {
    $conn->query("
        CREATE TABLE IF NOT EXISTS sms_settings (
            setting_key VARCHAR(64) PRIMARY KEY,
            setting_value TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Загружаем настройки SMS из БД с fallback на константы
 */
function loadSmsSettings() {
    try {
        $conn = connectToDatabase();
        ensureSmsSettingsTable($conn);

        $settings = [];
        $result = $conn->query("SELECT setting_key, setting_value FROM sms_settings");
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
        $conn->close();

        // Fallback на константы, если в БД нет значений
        $settings['SMS_PROVIDER'] = $settings['SMS_PROVIDER'] ?? (defined('SMS_PROVIDER') ? SMS_PROVIDER : 'emulation');
        $settings['SMSRU_API_ID'] = $settings['SMSRU_API_ID'] ?? (defined('SMSRU_API_ID') ? SMSRU_API_ID : '');
        $settings['SMSCRU_LOGIN'] = $settings['SMSCRU_LOGIN'] ?? (defined('SMSCRU_LOGIN') ? SMSCRU_LOGIN : '');
        $settings['SMSCRU_PASSWORD'] = $settings['SMSCRU_PASSWORD'] ?? (defined('SMSCRU_PASSWORD') ? SMSCRU_PASSWORD : '');
        $settings['API_KEY'] = $settings['API_KEY'] ?? (defined('API_KEY') ? API_KEY : '');

        return $settings;
    } catch (Exception $e) {
        // При ошибке возвращаем значения из констант
        return [
            'SMS_PROVIDER' => defined('SMS_PROVIDER') ? SMS_PROVIDER : 'emulation',
            'SMSRU_API_ID' => defined('SMSRU_API_ID') ? SMSRU_API_ID : '',
            'SMSCRU_LOGIN' => defined('SMSCRU_LOGIN') ? SMSCRU_LOGIN : '',
            'SMSCRU_PASSWORD' => defined('SMSCRU_PASSWORD') ? SMSCRU_PASSWORD : '',
            'API_KEY' => defined('API_KEY') ? API_KEY : '',
        ];
    }
}

/**
 * Класс для работы с SMS.ru API
 */
class SmsRuProvider {
    private $apiId;
    private $apiUrl = 'https://sms.ru/sms/send';
    
    public function __construct($apiId) {
        $this->apiId = $apiId;
    }
    
    /**
     * Отправка SMS через SMS.ru
     */
    public function sendSms($phoneNumber, $message) {
        // Форматирование номера телефона (убираем + и пробелы)
        $phone = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // Параметры запроса
        $params = [
            'api_id' => $this->apiId,
            'to' => $phone,
            'msg' => $message,
            'json' => 1
        ];
        
        // Формируем URL с параметрами
        $url = $this->apiUrl . '?' . http_build_query($params);
        
        // Отправка запроса через cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'status' => 'Ошибка',
                'message' => 'Ошибка соединения: ' . $error
            ];
        }
        
        if ($httpCode !== 200) {
            return [
                'success' => false,
                'status' => 'Ошибка',
                'message' => 'HTTP Error: ' . $httpCode
            ];
        }
        
        // Парсим JSON ответ
        $data = json_decode($response, true);
        
        if (!$data) {
            return [
                'success' => false,
                'status' => 'Ошибка',
                'message' => 'Неверный формат ответа от сервера'
            ];
        }
        
        // Проверяем статус ответа
        if (isset($data['status']) && $data['status'] === 'OK') {
            // Проверяем статус конкретной SMS
            if (isset($data['sms'][$phone])) {
                $smsStatus = $data['sms'][$phone];
                if ($smsStatus['status'] === 'OK') {
                    return [
                        'success' => true,
                        'status' => 'Доставлено',
                        'message' => 'SMS успешно отправлено',
                        'sms_id' => $smsStatus['sms_id'] ?? null
                    ];
                } else {
                    return [
                        'success' => false,
                        'status' => 'Ошибка',
                        'message' => $smsStatus['status_text'] ?? 'Ошибка отправки SMS'
                    ];
                }
            }
        }
        
        return [
            'success' => false,
            'status' => 'Ошибка',
            'message' => $data['status_text'] ?? 'Неизвестная ошибка'
        ];
    }
}

/**
 * Класс для работы с SMSC.ru API
 */
class SmscRuProvider {
    private $login;
    private $password;
    private $apiUrl = 'https://smsc.ru/sys/send.php';
    
    public function __construct($login, $password) {
        $this->login = $login;
        $this->password = $password;
    }
    
    /**
     * Отправка SMS через SMSC.ru
     */
    public function sendSms($phoneNumber, $message) {
        // Форматирование номера телефона
        $phone = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // Параметры запроса
        $params = [
            'login' => $this->login,
            'psw' => $this->password,
            'phones' => $phone,
            'mes' => $message,
            'fmt' => 3 // JSON формат
        ];
        
        // Формируем URL с параметрами
        $url = $this->apiUrl . '?' . http_build_query($params);
        
        // Отправка запроса через cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'status' => 'Ошибка',
                'message' => 'Ошибка соединения: ' . $error
            ];
        }
        
        if ($httpCode !== 200) {
            return [
                'success' => false,
                'status' => 'Ошибка',
                'message' => 'HTTP Error: ' . $httpCode
            ];
        }
        
        // Парсим JSON ответ
        $data = json_decode($response, true);
        
        if (!$data) {
            return [
                'success' => false,
                'status' => 'Ошибка',
                'message' => 'Неверный формат ответа от сервера'
            ];
        }
        
        // Проверяем наличие ошибки
        if (isset($data['error'])) {
            return [
                'success' => false,
                'status' => 'Ошибка',
                'message' => $data['error']
            ];
        }
        
        // Если есть id, значит отправка успешна
        if (isset($data['id'])) {
            return [
                'success' => true,
                'status' => 'Доставлено',
                'message' => 'SMS успешно отправлено',
                'sms_id' => $data['id']
            ];
        }
        
        return [
            'success' => false,
            'status' => 'Ошибка',
            'message' => 'Неизвестная ошибка'
        ];
    }
}

/**
 * Универсальный провайдер с API ключом
 */
class ApiKeyProvider {
    private $apiKey;
    private $apiUrl;
    
    public function __construct($apiKey, $apiUrl = '') {
        $this->apiKey = $apiKey;
        $this->apiUrl = $apiUrl;
    }
    
    /**
     * Отправка SMS через API с ключом
     */
    public function sendSms($phoneNumber, $message) {
        // Форматирование номера телефона
        $phone = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // Если URL не указан, используем универсальный формат
        if (empty($this->apiUrl)) {
            // Универсальный формат для большинства провайдеров
            // Провайдер должен быть настроен через переменную окружения или конфиг
            return [
                'success' => false,
                'status' => 'Ошибка',
                'message' => 'API URL не настроен. Укажите URL API провайдера.'
            ];
        }
        
        // Параметры запроса (универсальный формат)
        $params = [
            'api_key' => $this->apiKey,
            'phone' => $phone,
            'message' => $message,
            'format' => 'json'
        ];
        
        // Формируем URL с параметрами
        $url = $this->apiUrl . '?' . http_build_query($params);
        
        // Отправка запроса через cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'status' => 'Ошибка',
                'message' => 'Ошибка соединения: ' . $error
            ];
        }
        
        if ($httpCode !== 200) {
            return [
                'success' => false,
                'status' => 'Ошибка',
                'message' => 'HTTP Error: ' . $httpCode
            ];
        }
        
        // Парсим JSON ответ
        $data = json_decode($response, true);
        
        if (!$data) {
            return [
                'success' => false,
                'status' => 'Ошибка',
                'message' => 'Неверный формат ответа от сервера'
            ];
        }
        
        // Универсальная проверка успешности (адаптируется под разные форматы)
        if (isset($data['status']) && ($data['status'] === 'OK' || $data['status'] === 'success')) {
            return [
                'success' => true,
                'status' => 'Доставлено',
                'message' => 'SMS успешно отправлено',
                'sms_id' => $data['id'] ?? $data['sms_id'] ?? null
            ];
        }
        
        if (isset($data['success']) && $data['success'] === true) {
            return [
                'success' => true,
                'status' => 'Доставлено',
                'message' => 'SMS успешно отправлено',
                'sms_id' => $data['id'] ?? $data['sms_id'] ?? null
            ];
        }
        
        return [
            'success' => false,
            'status' => 'Ошибка',
            'message' => $data['error'] ?? $data['message'] ?? 'Неизвестная ошибка'
        ];
    }
}

/**
 * Класс для эмуляции отправки SMS (для тестирования)
 */
class EmulationProvider {
    /**
     * Эмуляция отправки SMS
     */
    public function sendSms($phoneNumber, $message) {
        // Эмуляция различных статусов отправки
        $statuses = ['Доставлено', 'Ошибка', 'В очереди'];
        $weights = [80, 15, 5]; // 80% успех, 15% ошибка, 5% в очереди
        
        $random = mt_rand(1, 100);
        $cumulative = 0;
        $selectedStatus = 'Доставлено';
        
        for ($i = 0; $i < count($statuses); $i++) {
            $cumulative += $weights[$i];
            if ($random <= $cumulative) {
                $selectedStatus = $statuses[$i];
                break;
            }
        }
        
        // Небольшая задержка для реалистичности
        usleep(100000); // 0.1 секунды
        
        return [
            'success' => $selectedStatus === 'Доставлено',
            'status' => $selectedStatus,
            'message' => $selectedStatus === 'Доставлено' ? 'SMS успешно отправлено (эмуляция)' : 'Ошибка отправки (эмуляция)'
        ];
    }
}

/**
 * Функция для получения провайдера SMS на основе конфигурации
 */
function getSmsProvider() {
    // Читаем настройки из БД (fallback на константы)
    $settings = loadSmsSettings();
    $providerType = $settings['SMS_PROVIDER'] ?? 'emulation';
    
    switch ($providerType) {
        case 'smsru':
            $apiId = $settings['SMSRU_API_ID'] ?? '';
            if (empty($apiId)) {
                throw new Exception('Не указан API ID для SMS.ru. Задайте его в настройках SMS.');
            }
            return new SmsRuProvider($apiId);
            
        case 'smscru':
            $login = $settings['SMSCRU_LOGIN'] ?? '';
            $password = $settings['SMSCRU_PASSWORD'] ?? '';
            if (empty($login) || empty($password)) {
                throw new Exception('Не указаны логин/пароль для SMSC.ru. Задайте их в настройках SMS.');
            }
            return new SmscRuProvider($login, $password);
            
        case 'apikey':
            $apiKey = $settings['API_KEY'] ?? '';
            if (empty($apiKey)) {
                throw new Exception('Не указан API ключ. Задайте его в настройках SMS.');
            }
            $apiUrl = $settings['API_URL'] ?? '';
            return new ApiKeyProvider($apiKey, $apiUrl);
            
        case 'emulation':
        default:
            return new EmulationProvider();
    }
}

/**
 * Универсальная функция отправки SMS
 * Использует провайдера из конфигурации
 */
function sendSms($phoneNumber, $message) {
    try {
        $provider = getSmsProvider();
        return $provider->sendSms($phoneNumber, $message);
    } catch (Exception $e) {
        return [
            'success' => false,
            'status' => 'Ошибка',
            'message' => $e->getMessage()
        ];
    }
}
?>


