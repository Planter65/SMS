<?php
/**
 *  Description: Отправка СМС через систему
 *
 *  Copyright 2017 by ISBC Group
 *  All rights reserved.
 */

namespace Src;

/**
 * Реализует операцию: Удаление телефонов из черного списка
 *
 * Class SmsActionBlacklistDelete
 * @package Qtsms
 */
final class SmsActionBlacklistDelete extends SmsAction implements SmsActionInterface
{
    protected $params = [
        'phones' => NULL, // обязательный
    ];

    public function __construct()
    {
        $this->action = self::ACTION_BLACKLIST_DELETE;
    }

    public function validateParams(array $params = NULL): bool
    {
        $prefix = 'validator_blacklist_delete';
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
