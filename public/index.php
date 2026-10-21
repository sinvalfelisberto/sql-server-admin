<?php
declare(strict_types=1);

$config = require __DIR__ . '/../app/bootstrap.php';

(new App\Core\Application(dirname(__DIR__), $config))->run(App\Http\Request::fromGlobals());
