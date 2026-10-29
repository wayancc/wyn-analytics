<?php

declare(strict_types=1);

namespace WynAnalytics\Http;

final class Response
{
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = ['Content-Type' => 'text/html; charset=utf-8'],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), [
            'Content-Type'  => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function svg(string $body): self
    {
        return new self(200, $body, [
            'Content-Type'  => 'image/svg+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=60',
        ]);
    }

    public static function redirect(string $url, int $status = 303): self
    {
        return new self($status, '', ['Location' => $url]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        echo $this->body;
    }
}
