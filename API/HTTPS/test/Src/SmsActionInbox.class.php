<?php
/**
 *  Description: Отправка СМС через систему
 *
 *  Copyright 2017 by ISBC Group
 *  All rights reserved.
 */

namespace Src;

/**
 * Реализует операцию: Получение входящих сообщений
 *
 * Class SmsActionInbox
 * @package Qtsms
 */
final class SmsActionInbox extends SmsAction implements SmsActionInterface
{
    protected $params = [
        'sib_num' => NULL, // обязательный
        'new_only' => NULL,
        'date_from' => NULL,
        'date_to' => NULL,
    ];

    public function __construct()
    {
        $this->action = self::ACTION_INBOX;
    }

    public function validateParams(array $params = NULL): bool
    {
        $prefix = 'validator_inbox';
        extract($params, EXTR_PREFIX_ALL, $prefix);

        if (empty(${$prefix . '_sib_num'})
        ) {
            $this->errorParams[] = 'Требуется указать обязательный параметр: sib_num';
        }

        if (!empty($this->errorParams)) {
            return FALSE;
        }
        return TRUE;
    }

}
