<?php

declare(strict_types=1);

namespace WynAnalytics;

use WynAnalytics\Http\Session;

/**
 * Plain-PHP templates in templates/. A template can wrap itself in a layout with $v->layout('name').
 */
final class View
{
    private ?string $layout = null;
    private array $layoutVars = [];

    public function __construct(
        private readonly string $dir,
        private readonly Config $config,
        private readonly Settings $settings,
        private readonly Session $session,
        private readonly ?array $user = null,
    ) {
    }

    public function render(string $template, array $vars = []): string
    {
        $this->layout = null;
        $content = $this->capture($template, $vars);

        while ($this->layout !== null) {
            $layout = $this->layout;
            $this->layout = null;
            $content = $this->capture($layout, $this->layoutVars + $vars + ['content' => $content]);
        }

        return $content;
    }

    public function layout(string $name, array $vars = []): void
    {
        $this->layout = $name;
        $this->layoutVars = $vars;
    }

    public function partial(string $template, array $vars = []): string
    {
        return $this->capture($template, $vars);
    }

    private function capture(string $template, array $vars): string
    {
        $file = $this->dir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Template not found: {$template}");
        }
        $v = $this;
        $settings = $this->settings;
        $user = $this->user;
        extract($vars, EXTR_SKIP);

        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function url(string $path = ''): string
    {
        return $this->config->baseUrl() . ltrim($path, '/');
    }

    /** Absolute URL, for links that leave the browser (alerts, heartbeat URLs, badges). */
    public function absolute(string $path = ''): string
    {
        return Urls::absolute($this->config, $path);
    }

    public function asset(string $path): string
    {
        $file = WYN_ROOT . '/public/assets/' . $path;

        return $this->url('assets/' . $path) . '?v=' . (is_file($file) ? substr(md5_file($file) ?: '', 0, 8) : WYN_VERSION);
    }

    public function csrf(): string
    {
        return '<input type="hidden" name="_token" value="' . $this->e($this->session->csrfToken()) . '">';
    }

    public function flashes(): array
    {
        return $this->session->takeFlashes();
    }

    /** URL with the current query plus changes. A null change removes the key. */
    public function link(string $path, array $query, array $changes = [], string $anchor = ''): string
    {
        $q = array_filter(array_replace($query, $changes), fn ($v) => $v !== null && $v !== '');
        ksort($q);

        return $this->url($path) . ($q ? '?' . http_build_query($q) : '') . ($anchor !== '' ? '#' . $anchor : '');
    }

    /** How a breakdown value reads on screen. */
    public function dimLabel(string $dim, string $value): string
    {
        if ($value === '(other)') {
            return 'Other';
        }
        if ($value === '') {
            return match ($dim) {
                'source', 'referrer' => 'Direct / None',
                'utm_source', 'utm_medium', 'utm_campaign' => '(not set)',
                default => 'Unknown',
            };
        }
        if ($dim === 'country') {
            return Geo::name($value);
        }

        return $value;
    }

    public function metric(string $metric, mixed $value): string
    {
        return Report::formatMetric($metric, $value);
    }

    public function compact(int|float $n): string
    {
        return Format::compact($n);
    }

    public function time(?int $ts, string $format = 'M j, H:i'): string
    {
        return $ts ? date($format, $ts) : '—';
    }

    public function ago(?int $ts): string
    {
        return $ts ? Format::ago($ts) : 'never';
    }
}
