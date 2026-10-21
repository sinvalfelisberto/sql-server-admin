<?php
declare(strict_types=1);

// Autoload PSR-4 simples: namespace App\ -> app/
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// Funções livres não são autoloadable: carrega os arquivos de funções puras.
require __DIR__ . '/Support/functions.php';
require __DIR__ . '/Models/functions.php';
require __DIR__ . '/Models/ddl.php';

return App\Core\Config::load(dirname(__DIR__) . '/config');
