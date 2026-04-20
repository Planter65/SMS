<?php
/**
 *  Description: Отправка СМС через систему
 *
 *  Copyright 2017 by ISBC Group
 *  All rights reserved.
 */

namespace Src;

/**
 * Реализует операцию: Проверка баланса
 *
 * Class SmsActionBalance
 * @package Qtsms
 */
final class SmsActionBalance extends SmsAction implements SmsActionInterface
{
    public function __construct()
    {
        $this->action = self::ACTION_BALANCE;
    }

}
