<?php
require_once __DIR__ . '/vendor/autoload.php';

use App\CLI\ConsoleKernel;

date_default_timezone_set('Asia/Shanghai');
$kernel = new ConsoleKernel();
exit($kernel->handle($argv));
