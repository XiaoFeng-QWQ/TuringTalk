<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Application;

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 启动应用
$app = new Application();
$app->run();