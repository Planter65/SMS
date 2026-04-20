<?php

header("Content-Type: text/xml; charset=UTF-8");

require('QTSMS.class.php');
require('test_config.php');

$sms = new QTSMS($cfg['login'], $cfg['password'], $cfg['host']);

// !!! Команда на кеширование запросов
$sms->start_multipost();
// Отправка смс
$sms->post_message('Привет', '+7******', 'Klaus');
// Отправка смс по группе
$sms->post_message_phl('Здраствуйте', 'test_http', 'Klaus');
// статус сообщения SMS_ID=""
$sms->status_sms_id( "12345678901234567890123" );
// статусы сообщений SMS_GROUP_ID=""
$sms->status_sms_group_id( "12345678901234567890123" );
// !!! отправить всё одним запросом и получить результат в XML
$result=$sms->process();


echo $result; // результат XML
