<?php
/**
 *  Description: Отправка СМС через систему
 *
 *  Copyright 2017 by ISBC Group
 *  All rights reserved.
 */

namespace Src;

interface SmsActionInterface
{

    public function __construct();

    /**
     * Вернуть название action
     * @return string
     */
    public function getActionName(): string;

    /**
     * Установить параметры запроса
     *
     * @param array $params
     */
    public function setParams(array $params);

    /**
     * Вернуть данные для тела POST запроса
     *
     * @return array
     */
    public function formPostFields(): array;

    /**
     * Валидация параметров при установке
     *
     * @param array $params
     * @return mixed
     */
    public function validateParams(array $params);

}
