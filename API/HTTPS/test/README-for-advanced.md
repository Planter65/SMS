
# Класс для отправки СМС через систему

---

#### Примечание:

Это руководство предназначено для опытных веб-разработчиков и предполагает,
что вы сможете самостоятельно разобраться в коде и использовать его возможности.

Если это не так, то читайте **"README.txt"**

Будем благодарны, если информацию о найденных багах, вы передадите нам - мы их поправим!

---
Реализован новый интерфейс отправки СМС для РHP 7.2 (в папке src)
Для совместимости со старым интерфейсом добавлен класс-обертка "`QTSMS.class.php`"
(работа с ним соответствует примерам из архива)

### пример работы с классом (новый интерфейс)

Создать клиент (соединение с CURL)
```php
$smsClient = new \Qtsms\SmsClient(
    'POST',
    'https',
    'myhost.ru'
);
$smsClient->setAuth('Vasiliy', 'Terkin');  
$smsClient->setProxy('192.168.0.1:1111'); // при необходимости (* см.ниже "Работа с прокси-сервером")
```

Для изменения запроса теперь достаточно создать новый Action, установить его параметры и загрузить его в клиент

#### action: Получить список запрещенных номеров
```php
$action = new \Qtsms\SmsActionBlacklist();
$errors = $action->setParams([
    'perp' => '3'
]);
```

#### action: Отправить сообщение
конкретным адресатам (список телефонов через запятую):
```php
$action = new \Qtsms\SmsActionPostSms();
$errors = $action->setParams([
    'message' => 'Hello sms',
    'target' => '89167777777', // список телефонов
    'sender' => 'Vasya',
]);
```
по кодовому имени контакт-листа:
```php
$action = new \Qtsms\SmsActionPostSms();
$errors = $action->setParams([
    'message' => 'Hello sms',
    'phl_codename' => 'my_birthday', // кодовое имя рассылки
    'sender' => 'Vasya',
]);
```

#### action: Получение входящих сообщений
```php
$action = new \Qtsms\SmsActionInbox();
$errors = $action->setParams([
    'sib_num' => 'Hello sms',
    'new_only' => '1',
]);
```

###### есть возможность валидации полей при установке параметров
```php
if (isset($errors) && $errors !== TRUE) {
    var_dump($errors); die();
}
```

После создания action его требуется загрузить в класс
```php
$smsClient->setAction($action);
```

и выполнить запрос на сервер
```php
$smsClient->sendRequest();
```

###### валидация ошибок после выполнения запроса (при необходимости)
```php
if (!empty($smsClient->getResponseError())) {
    echo 'ошибка: ' . $smsClient->getResponseError();
}
```

#### получить ответ от сервера (в формате XML)
```php
$content = $smsClient->getResponseContent();
```
---

## Мультипост
Класс поддерживает режим мультизапроса - кэширование всех actions и отправку их одним запросом.  
Для этого требуется включить режим:  
```php
$smsClient->start_multipost();
```

Далее можно добавлять actions (они не будут выполняться сразу же, а будут накапливаться в буфере):  
```php
$action = new \Qtsms\SmsActionBlacklist(); // получить черный список телефонов
$action->setParams([
    'perp' => '5'
]);
$smsClient->setAction($action);
```
```php
$action = new \Qtsms\SmsActionPostSms();
$errors = $action->setParams([
    'message' => 'Hello!',
    'target' => '89167777777',
    'sender' => 'Vasya',
]);
$smsClient->setAction($action);
```

Выполнение мультизапроса:
```php
$result_xml = $smsClientMin->process();
```

---
## Работа с прокси-сервером


Если в вашей сети для выхода в интернет используется прокси-сервер, то используйте методы:

- Задать адрес прокси-сервера в виде строки вида "ip:port" (например: '192.168.0.1:7777')
    ```php
    setProxy ( string $proxyData )
    set_proxy ( string $proxyData ) // если работаете через класс-обертку
    ```
- Если для подключения к прокси требуется указать Логин/пароль, то задайте в формате: "username:password"
    ```php
    setProxyUserPwd ( string $proxyData )
    set_proxy_user_pwd ( string $proxyData ) // если работаете через класс-обертку
    ```

---
## Установка сертификата
CURL для своей работы требует установки сертификата. По-умолчанию, проверка сертификата отключена.

Если вы хотите ее включить, то ниже приведен один из рабочих вариантов:

  1. Скачать свежий сертификат  
      - Зайти на страницу CURL: https://curl.se/docs/caextract.html  
      - скачать файл cacert.pem
      - положить его в папку qtsms/src (он должен так и называться "cacert.pem")
    
  2. Указать CURL путь к этой папке
      - метод `setCurlCertificatePath(\<path_to_cert>)`
  
  3. Убедиться, что опция **CURLOPT_SSL_VERIFYPEER** установлена в **TRUE**
  
Если вы работаете с отправкой через класс-обертку, то порядок действий следующий:

  1. аналогично п.1 выше
  2. в файле `QTSMS.class.php` в конструкторе требуется сделать следующие операции:
      - закомментировать строку с установкой `setCurlCertificateCheck(FALSE)`, либо сделать `TRUE`
      - раскомментировать строки с подключением файла сертификата: `setCurlCertificatePath(...)`
      т.е. эта часть кода должна выглядеть так (см. файл `QTSMS.class.php`)
     ```php
      // по-умолчанию проверка сертификата узла для CURL отключена
      //  $this->smsClient->setCurlCertificateCheck(FALSE);
      // указать CURL путь к файлу с сертификатом (если требуется включить проверку)
      $realpath = realpath(__DIR__ . $this->pathToCertPem);
      $this->smsClient->setCurlCertificatePath($realpath);
      
      ```

Не забывайте, что сертификаты имеют свойство "протухать", поэтому если сервис вдруг перестал работать, 
в числе прочего попробуйте обновить сертификат (см. п.1) 

---
### Примечания:
- не забывайте уничтожать объект SmsAction после использования, это актуально при создании большого количества объектов SmsAction
