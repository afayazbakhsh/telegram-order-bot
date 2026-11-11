<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\App;
use App\Config;

$config = Config::fromEnvironment();
$app = new App($config);
$app->handle();
