<?php
declare(strict_types=1);

// Simple PSR-4 autoload: namespace App\ -> app/
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// Free functions cannot be autoloaded: load the files with the pure functions.
require __DIR__ . '/Support/functions.php';
require __DIR__ . '/Models/functions.php';
require __DIR__ . '/Models/ddl.php';

return App\Core\Config::load(dirname(__DIR__) . '/config');
