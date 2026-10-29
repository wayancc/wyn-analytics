<?php

declare(strict_types=1);

// PHP's built-in dev server: serve real files (CSS, JS, fonts) directly.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $app = WynAnalytics\App::boot(WYN_ROOT);
    $request = WynAnalytics\Http\Request::fromGlobals($app->config->baseUrl(), (bool) $app->config->get('trust_proxy'));
    $app->handle($request)->send();
} catch (Throwable $e) {
    // Never echo details: they could include paths. They go to the PHP error log.
    error_log('[wyn-analytics] ' . $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "Wyn Analytics hit an error and couldn't finish this request.\n\n";
    echo $e instanceof RuntimeException && str_contains($e->getMessage(), 'storage')
        ? $e->getMessage() . "\n"
        : "Details were written to the PHP error log.\n";
}
