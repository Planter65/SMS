<?php
/**
 *  Description: Отправка СМС через систему
 *
 *  Copyright 2017 by ISBC Group
 *  All rights reserved.
 */

namespace Src;

/**
 * Реализует клиент для подключения к серверу отправки СМС.
 *
 * Class SmsClient
 * @package Qtsms
 */
class SmsClient
{
    /** @var \CurlHandle */
    private $ch;

    /** @var string Ваш логин в системе */
    private $login;

    /** @var string Ваш пароль в системе */
    private $password;

    /**
     * @var array Прочие параметры авторизации
     *   - gzip
     *   - HTTP_ACCEPT_LANGUAGE
     *   - CLIENTADR
     *   - comment
     */
    private $authContext = [];

    /** @var string<array> */
    private $method = 'POST';

    /** @var string протокол */
    private $protocol = 'https';

    /** @var string адрес сервера отправки сообщений (без http) */
    private $hostname;

    /** @var string путь на сервере */
    // private $path = '/public/http/';
    private $path;

    /** @var string Путь к прокси-серверу, если он есть,
     * В формате <ip>:<port>
     */
    private $proxy;

    /**
     * @var string Логин и пароль к прокси-серверу, если есть.
     * В формате: <username>:<password>
     * Не имеет значения, если не заполнено свойство $proxy
     */
    private $proxyUserPwd;

    /** @var array Заголовки, отправляемые в запросе */
    private $httpHeaders = [
        'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
    ];

    /** @var SmsActionInterface[] */
    private $action = [];

    /** @var mixed Хранит данные последнего выполненного запроса */
    private $responseContent;

    /** @var mixed Хранит информацию о последней ошибке */
    private $responseError;

    /** @var mixed Хранит информацию о последней операции */
    private $responseInfo;

    /** @var bool Флаг мультизапроса (несколько действий в одном запросе) */
    private $multipost = FALSE;

    /** @var array Данные, передаваемые на сервер */
    private $post_data = [];

    /**
     * Установить параметры подключения к серверу
     *
     * SmsClient constructor.
     * @param string|null $method
     * @param string|null $protocol
     * @param string|null $hostname
     * @param string|null $path
     */
    public function __construct(
        string $method = NULL,
        string $protocol = NULL,
        string $hostname = NULL,
        string $path = NULL)
    {
        $this->ch = curl_init();
        if (!empty($method)) {
            $this->method = $method;
        }
        if (!empty($protocol)) {
            $this->protocol = $protocol;
        }
        if (!empty($hostname)) {
            $this->hostname = $hostname;
        }
        if (!empty($path)) {
            $this->path = $path;
        }

        $this->connectSettings();
    }

    /**
     * Установить параметры авторизации
     *
     * @param string $login
     * @param string $password
     * @param array $context Остальные необязательные поля для авторизации
     */
    public function setAuth(string $login, string $password, array $context = [])
    {
        $this->login = $login;
        $this->password = $password;

        $this->authContext = [
            'gzip' => 'none',
            'CLIENTADR' => $_SERVER['REMOTE_ADDR'] ?? false,
            'HTTP_ACCEPT_LANGUAGE' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? false,
            'comment' => ''
        ];

        if (!empty($context)) {
            $this->formAuthContext($context);
        }
    }

    /**
     * Сформировать параметры контекста авторизации
     * @param array $context
     */
    public function formAuthContext(array $context)
    {
        $allowedFields = [
            'gzip',
            'HTTP_ACCEPT_LANGUAGE',
            'CLIENTADR',
            'comment',
        ];

        foreach ($context as $key => $value) {
            if (!in_array($key, $allowedFields)) {
                continue;
            }
            $this->authContext[$key] = $value;
        }
    }

    /**
     * @param string $proxyData Адрес прокси-сервера в виде строки "ip:port"
     */
    public function setProxy(string $proxyData)
    {
        $this->proxy = $proxyData;
        $this->setProxyParams();
    }

    /**
     * @param string $proxyData Логин/пароль к прокси-серверу в виде строки "username:password"
     */
    public function setProxyUserPwd(string $proxyData)
    {
        $this->proxyUserPwd = $proxyData;
        $this->setProxyParams();
    }

    /**
     * @param bool $verifyPeer Управлять проверкой CURL сертификата узла
     */
    public function setCurlCertificateCheck(bool $verifyPeer)
    {
        if (isset($this->ch)) {
            curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, $verifyPeer);
        }
    }

    /**
     * @param string $pathToCert Указать путь к файлу сертификата (необходим для работы cURL)
     */
    public function setCurlCertificatePath(string $pathToCert)
    {
        if (isset($this->ch)) {
            curl_setopt($this->ch, CURLOPT_CAINFO, $pathToCert);
        }
    }

    /**
     * Установить action для выполнения запроса
     *
     * @param SmsActionInterface $action
     */
    public function setAction(SmsActionInterface $action)
    {
        // если не активирован режим Мультипост, то может быть только один action
        if (!$this->multipost) {
            $this->action = [];
        }
        $this->action[] = $action;
    }

    /**
     * Общие установки CURL
     */
    private function connectSettings()
    {
        $url = $this->protocol . '://' . $this->hostname . $this->path;

        curl_setopt_array($this->ch, [
            CURLOPT_URL => $url,
            CURLOPT_HEADER => FALSE,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $this->buildHeaders(),
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_FOLLOWLOCATION => FALSE,
            CURLOPT_USERAGENT => 'AISMS PHP class',
        ]);
    }

    /**
     * Выполнить запрос
     */
    public function sendRequest()
    {
        // собрать поля для POST-запроса
        $postFields = [
            'user' => $this->login,
            'pass' => $this->password,
            'gzip' => $this->authContext['gzip'],
            'HTTP_ACCEPT_LANGUAGE' => $this->authContext['HTTP_ACCEPT_LANGUAGE'],
            'CLIENTADR' => $this->authContext['CLIENTADR'],
            'comment' => $this->authContext['comment'],
        ];

        // Установить прочие параметры запроса (зависит от классов, реализующих SmsActionInterface)
        $postFieldsAction = NULL;
        if (!$this->multipost) {
            $postFieldsAction = $this->action[0]->formPostFields();
        } else {
            foreach ($this->action as $action) {
                $postFieldsAction['data'][] = $action->formPostFields();
            }
        }
        $postFields = array_merge($postFields, $postFieldsAction);
        $query = http_build_query($postFields, '', '&');

        if ($this->method === 'POST') {
            curl_setopt($this->ch, CURLOPT_POST, TRUE);
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, $query);
        }

        $this->responseContent = curl_exec($this->ch);
        $this->responseError = curl_error($this->ch);
        $this->responseInfo = curl_getinfo($this->ch);

        if (is_string($this->responseError) && !empty($this->responseError)) {
            return $this->responseError;
        }
        return $this->responseContent;
    }

    public function isMultipost()
    {
        return $this->multipost;
    }

    public function getResponseContent()
    {
        return $this->responseContent;
    }

    /**
     * @return mixed
     */
    public function getResponseError()
    {
        return $this->responseError;
    }

    /**
     * @return mixed
     */
    public function getResponseInfo()
    {
        return $this->responseInfo;
    }

    /**
     * ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ
     */

    /**
     * Вернуть массив с http-заголовками, подготовленный к использованию CURL
     *
     * @return array
     */
    private function buildHeaders(): array
    {
        $headers = [];
        foreach ($this->httpHeaders as $key => $header) {
            $headers[] = $key . ': ' . $header;
        }
        return $headers;
    }

    /**
     * Установить/заменить значение заголовка
     *
     * @param string $headerName
     * @param string|null $headerValue
     */
    public function addHeader(string $headerName, string $headerValue = NULL)
    {
        $this->httpHeaders[$headerName] = $headerValue;
    }

    /**
     * Установить параметры прокси-сервера
     */
    public function setProxyParams()
    {
        if (!empty($this->proxy)) {
            curl_setopt($this->ch, CURLOPT_PROXY , $this->proxy);
            if (!empty($this->proxyUserPwd)) {
                curl_setopt($this->ch, CURLOPT_PROXYUSERPWD, $this->proxyUserPwd);
            }
        }
    }

    /**
     * Команда на начало мультизапроса
     */
    public function startMultipost()
    {
        $this->multipost = TRUE;
    }

}
