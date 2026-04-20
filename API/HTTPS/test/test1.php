<?php


header("Content-Type: text/xml; charset=UTF-8");

require('QTSMS.class.php');
require('test_config.php');

$sms = new QTSMS($cfg['login'], $cfg['password'], $cfg['host']);

$sms_text = 'Привет!';
$sender_name = 'Klaus';
$period = 600;

// Отправка СМС сообщения по списку адресатов
$result = $sms->post_message($sms_text, '+7********', $sender_name,'x124127456',$period);

// Отправка СМС по кодовому имени контакт листа
//$result = $sms->post_message_phl($sms_text, 'test_http', $sender_name, 'x123127456', $period);

header("Content-Type: text/xml; charset=UTF-8");
echo $result; // результат XML
