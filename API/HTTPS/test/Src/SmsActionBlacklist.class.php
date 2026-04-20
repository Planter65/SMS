<?php
/**
 *  Description: Отправка СМС через систему
 *
 *  Copyright 2017 by ISBC Group
 *  All rights reserved.
 */

namespace Src;

/**
 * Реализует операцию: Получение списка телефонов, занесенных в черный список
 *
 * Class SmsActionBlacklist
 * @package Qtsms
 */
final class SmsActionBlacklist extends SmsAction implements SmsActionInterface
{
    protected $params = [
        'perp' => NULL,
        'page' => NULL,
        'search' => NULL,
    ];

    public function __construct()
    {
        $this->action = self::ACTION_BLACKLIST;
    }

}
