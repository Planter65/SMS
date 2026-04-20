<?php

header("Content-Type: text/xml; charset=UTF-8");
	
require('QTSMS.class.php');
require('test_config.php');

$sms = new QTSMS($cfg['login'], $cfg['password'], $cfg['host']);

// При передаче параметров SMS_ID и SMS_GROUP_ID в методы класса QTSMS внимательно следите за тем, чтобы они передавались как строки!
// Длинные числа могут трансформироваться в экспоненциальную запись, и сервис вернёт вам ошибку (server error)

// данные о сообщении SMS_ID=""
// $result = $sms->status_sms_id( "12345678901234567890123" );

// данные о сообщениях отправки SMS_GROUP_ID=""
// $result = $sms->status_sms_group_id( "12345678901234567890123" );

// Получить данные сообщений отправленных с даты по дату
$result = $sms->status_sms_date('07.06.2017 00:00:00', '08.06.2017 23:00:00');

echo $result; // результат XML
