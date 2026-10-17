<?php
$base = 'http://127.0.0.1:8080/orangehrm/web/proxy';
echo file_get_contents("$base/get_manpower_list.php") . "\n";
