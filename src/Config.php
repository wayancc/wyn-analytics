<?php

declare(strict_types=1);

namespace WynAnalytics;

/**
 * Server-level settings from config.php. Sites, goals, the theme and retention
 * are edited in the dashboard and stored in the database.
 */
final class Config
{
    private const DEFAULTS = [
        'database'    => 'storage/wyn-analytics.sqlite',
        'base_url'    => '/',              // path prefix when installed in a subfolder
        'app_url'     => '',               // full URL of this install, e.g. https://stats.example.com
        'timezone'    => 'UTC',            // default time zone for new sites
        'trust_proxy' => false,            // behind Cloudflare / a proxy: read the visitor IP and CF-IPCountry
        'script_path' => '/wyn.js',        // where the tracking script is served
        'event_path'  => '/wyn/e',         // where events are sent
        'geo_db'      => 'storage/wyn-geo.bin', // country database built by bin/wyn-analytics geo:update
    ];

    private function __construct(private array $data, public readonly string $root)
    {
    }

    public static function load(string $root, array $overrides = []): self
    {
        $file = $root . '/config.php';
        $user = is_file($file) ? require $file : [];
        $data = array_replace(self::DEFAULTS, is_array($user) ? $user : [], $overrides);
        if ($env = getenv('WYN_DATABASE')) {
            $data['database'] = $env;
        }
        $data['base_url'] = rtrim('/' . trim((string) $data['base_url'], '/'), '/') . '/';
        foreach (['script_path', 'event_path'] as $key) {
            $data[$key] = '/' . trim((string) $data[$key], '/');
        }

        return new self($data, $root);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function databasePath(): string
    {
        return $this->path((string) $this->data['database']);
    }

    public function geoPath(): string
    {
        return $this->path((string) $this->data['geo_db']);
    }

    public function storageDir(): string
    {
        $db = $this->databasePath();

        return $db === ':memory:' ? sys_get_temp_dir() : dirname($db);
    }

    public function baseUrl(): string
    {
        return $this->data['base_url'];
    }

    private function path(string $path): string
    {
        return str_starts_with($path, '/') || $path === ':memory:' ? $path : $this->root . '/' . $path;
    }
}
