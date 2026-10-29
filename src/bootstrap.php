<?php

declare(strict_types=1);

/*
 * Wyn Analytics has no Composer dependencies. This file is the whole autoloader.
 */

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('Wyn Analytics needs PHP 8.2 or newer. This server runs PHP ' . PHP_VERSION . '.');
}

foreach (['pdo_sqlite', 'mbstring'] as $ext) {
    if (!extension_loaded($ext)) {
        http_response_code(500);
        exit("Wyn Analytics needs the PHP extension \"{$ext}\". Enable it in your host's PHP settings.");
    }
}

// Stack traces must not carry function arguments: they include visitor IPs and browser
// strings (Salts::visitorId, Geo::country), and traces end up in the PHP error log.
ini_set('zend.exception_ignore_args', '1');

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'WynAnalytics\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 13)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

define('WYN_ROOT', dirname(__DIR__));
define('WYN_VERSION', '0.1.0');
