<?php
include './Shudu.php';
$obj = new Shudu(file_get_contents('./shudu2.txt'));
$obj->run();