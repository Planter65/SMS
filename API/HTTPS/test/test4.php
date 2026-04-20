<?php

header("Content-Type: text/xml; charset=UTF-8");

require('QTSMS.class.php');
require('test_config.php');

$sms = new QTSMS($cfg['login'], $cfg['password'], $cfg['host']);

// получение баланса
$result = $sms->get_balance();

echo $result; // результат XML
