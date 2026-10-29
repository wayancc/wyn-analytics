<?php

declare(strict_types=1);

namespace WynAnalytics;

final class Urls
{
    /**
     * Absolute URL. Uses app_url from config.php (the full address of the Wyn Forms install,
     * including any subfolder), or the current request when app_url isn't set.
     */
    public static function absolute(Config $config, string $path = ''): string
    {
        $origin = rtrim((string) $config->get('app_url'), '/');
        if ($origin === '') {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            $origin = ($https ? 'https://' : 'http://') . $host . rtrim($config->baseUrl(), '/');
        }

        return $origin . '/' . ltrim($path, '/');
    }
}
