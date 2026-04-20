<?php
/**
 *  Description: Отправка СМС через систему
 *
 *  Copyright 2017 by ISBC Group
 *  All rights reserved.
 */

namespace Src;

/**
 * Реализует операцию: Получение данных и статусов сообщений
 *
 * Class SmsActionStatus
 * @package Qtsms
 */
final class SmsActionStatus extends SmsAction implements SmsActionInterface
{
    protected $params = [
        'sms_id' => NULL,
        'sms_group_id' => NULL,
        'date_from' => NULL,
        'date_to' => NULL,
    ];

    public function __construct()
    {
        $this->action = self::ACTION_STATUS;
    }

    public function validateParams(array $params = NULL): bool
    {
        // пример валидатора для класса отправки СМС
        $prefix = 'validator_status';
        extract($params, EXTR_PREFIX_ALL, $prefix);

        // должен быть указан хотя бы один из параметров sms_id, sms_group_id или date_from+date_to
        if (empty(${$prefix . '_sms_id'})
            && empty(${$prefix . '_sms_group_id'})
            && (empty(${$prefix . '_date_from'}) || empty(${$prefix . '_date_to'}))
        ) {
            $this->errorParams[] = 'Должен быть указан хотя бы один из параметров: '
            . 'sms_id, sms_group_id, date_from, date_to';
        }

        if (!empty($this->errorParams)) {
            return FALSE;
        }
        return TRUE;
    }

}
