<?php
/**
 *  Description: Отправка СМС через систему
 *
 *  Copyright 2017 by ISBC Group
 *  All rights reserved.
 */

namespace Src;

/**
 * Базовый родительский класс для actions
 * Class SmsAction
 * @package Qtsms
 */
abstract class SmsAction
{
    const ACTION_SENDSMS = 'post_sms';
    const ACTION_STATUS = 'status';
    const ACTION_BALANCE = 'balance';
    const ACTION_INBOX = 'inbox';
    const ACTION_BLACKLIST = 'blacklist';
    const ACTION_BLACKLIST_ADD = 'blacklist_add';
    const ACTION_BLACKLIST_DELETE = 'blacklist_delete';

    /** @var string Название действия */
    protected $action;

    /** @var array Список параметров */
    protected $params = [];

    /**
     * @var array Хранит список ошибок при установке параметров
     */
    protected $errorParams = [];

    /**
     * Установить параметры
     * @param array|null $params
     * @return bool|string[]
     */
    public function setParams(array $params = NULL)
    {
        if (!$this->validateParams($params)) {
            return $this->errorParams;
        }

        if (!empty($params)) {
            foreach ($params as $key => $param) {
                if (array_key_exists($key, $this->params)) {
                    $this->params[$key] = $param;
                }
            }
        }

        return TRUE;
    }

    /**
     * Вернуть название action
     * @return string
     */
    public function getActionName(): string
    {
        return $this->action;
    }

    /**
     * Вернуть поля для POST-запроса
     *
     * @return string[]
     */
    public function formPostFields(): array
    {
        $postFields = [
            'action' => $this->getActionName()
        ];

        foreach ($this->params as $key => $param) {
            $postFields[$key] = $param;
        }

        return $postFields;
    }

    /**
     * Валидация устанавливаемых параметров
     *
     * @return bool|string[]
     */
    public function validateParams(array $params = NULL)
    {
        if (!empty($this->errorParams)) {
            return $this->errorParams;
        }
        return TRUE;
    }

}
