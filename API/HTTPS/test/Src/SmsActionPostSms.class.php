<?php
/**
 *  Description: Отправка СМС через систему
 *
 *  Copyright 2017 by ISBC Group
 *  All rights reserved.
 */

namespace Src;

/**
 * Реализует операцию: Отправка сообщения
 *
 * Class SmsActionPostSms
 * @package Qtsms
 */
final class SmsActionPostSms extends SmsAction implements SmsActionInterface
{
    protected $params = [
        'message' => NULL,
        'target' => NULL,
        'phl_codename' => NULL,
        'sender' => NULL,
        'post_id' => NULL,
        'period' => NULL,
        'time_period' => NULL,
        'time_local' => NULL,
        'autotrimtext' => NULL,
        'sms_type' => NULL,
        'wap_url' => NULL,
        'wap_expires' => NULL,
    ];

    public function __construct()
    {
        $this->action = self::ACTION_SENDSMS;
    }

    public function validateParams(array $params = NULL): bool
    {
        // пример валидатора для класса отправки СМС
        $prefix = 'validator_post_sms';
        extract($params, EXTR_PREFIX_ALL, $prefix);

        if (!empty(${$prefix . '_phl_codename'}) && !empty(${$prefix . '_target'})) {
            $this->errorParams[] = 'Несовместимые параметры в одном запросе: '
                . 'phl_codename, target';
        }

        if (!empty($this->errorParams)) {
            return FALSE;
        }
        return TRUE;
    }

}
