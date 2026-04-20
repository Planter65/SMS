<?php
/**
 *  Description: Отправка СМС через систему
 *
 *  Copyright 2017 by ISBC Group
 *  All rights reserved.
 */

namespace Src;

/**
 * Реализует операцию: Добавление телефонов в черный список
 *
 * Class SmsActionBlacklistAdd
 * @package Qtsms
 */
final class SmsActionBlacklistAdd extends SmsAction implements SmsActionInterface
{
    protected $params = [
        'phones' => NULL, // обязательный
    ];

    public function __construct()
    {
        $this->action = self::ACTION_BLACKLIST_ADD;
    }

    public function validateParams(array $params = NULL): bool
    {
        $prefix = 'validator_blacklist_add';
        extract($params, EXTR_PREFIX_ALL, $prefix);

        if (empty(${$prefix . '_phones'})
        ) {
            $this->errorParams[] = 'Требуется указать обязательный параметр: phones';
        }

        if (!empty($this->errorParams)) {
            return FALSE;
        }
        return TRUE;
    }

}
