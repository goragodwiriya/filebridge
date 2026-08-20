<?php

declare(strict_types=1);

use FileBridge\App;
use FileBridge\Http\Api;
use FileBridge\Http\Request;

require __DIR__ . '/vendor/autoload.php';

$app = App::boot(__DIR__);
$app->startSession();

header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

(new Api($app, new Request()))->handle();
