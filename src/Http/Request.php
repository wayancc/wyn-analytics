<?php

declare(strict_types=1);

namespace WynAnalytics\Http;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,    // relative to the base URL, always starts with "/"
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly string $ip = '0.0.0.0',
        public readonly bool $secure = false,
        public readonly array $headers = [],   // lower-case names
        public readonly string $body = '',
    ) {
    }

    public static function fromGlobals(string $base, bool $trustProxy): self
    {
        $path = self::pathFrom((string) ($_SERVER['REQUEST_URI'] ?? '/'), $base);

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if ($trustProxy) {
            $forwarded = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($forwarded !== '') {
                $ip = trim(explode(',', $forwarded)[0]);
            }
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($trustProxy && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        return new self(
            method: $method,
            path: $path,
            query: $_GET,
            post: $_POST,
            ip: $ip,
            secure: $secure,
            headers: self::headersFromServer(),
            // Tracking events arrive as a small text/plain or JSON body (sendBeacon). 16 KB is plenty.
            body: $method === 'POST' && $_POST === [] ? (string) file_get_contents('php://input', length: 16_384) : '',
        );
    }

    public static function pathFrom(string $uri, string $base): string
    {
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        if ($base !== '/' && str_starts_with($path, rtrim($base, '/'))) {
            $path = substr($path, strlen(rtrim($base, '/')));
        }

        return '/' . trim($path, '/');
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    private static function headersFromServer(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return $headers;
    }

    public function input(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? $default;

        return is_string($value) ? trim($value) : $default;
    }

    public function checkbox(string $key): int
    {
        return isset($this->post[$key]) && $this->post[$key] !== '0' ? 1 : 0;
    }
}
