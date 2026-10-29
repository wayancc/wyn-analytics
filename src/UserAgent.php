<?php

declare(strict_types=1);

namespace WynAnalytics;

/**
 * Small user-agent reader: browser, OS, device type, and a bot check.
 * Only the names are kept; versions and the raw string are never stored.
 */
final class UserAgent
{
    /** Substrings (lower case) that mark crawlers, monitors, previews, scripts and headless browsers. */
    private const BOTS = [
        'bot', 'crawl', 'spider', 'slurp', 'scrape', 'fetch', 'headless', 'phantomjs', 'puppeteer', 'playwright',
        'selenium', 'webdriver', 'lighthouse', 'pagespeed', 'gtmetrix', 'pingdom', 'uptime', 'monitor', 'vigil',
        'curl/', 'wget/', 'python', 'java/', 'go-http-client', 'okhttp', 'axios/', 'node-fetch', 'undici', 'httpclient',
        'libwww', 'guzzlehttp', 'facebookexternalhit', 'embedly', 'preview', 'whatsapp', 'telegram', 'discord', 'slack',
        'yandex', 'baiduspider', 'bingpreview', 'mediapartners', 'adsbot', 'apis-google', 'feedfetcher', 'chrome-lighthouse',
        'cypress', 'electron/', 'prerender', 'rendertron', 'archive.org', 'ia_archiver', 'censys', 'zgrab', 'masscan',
        'gptbot', 'chatgpt-user', 'oai-searchbot', 'claudebot', 'claude-web', 'anthropic', 'perplexity', 'bytespider', 'ccbot',
    ];

    public static function isBot(string $ua): bool
    {
        $ua = strtolower(trim($ua));
        if ($ua === '' || strlen($ua) < 20 || !str_contains($ua, 'mozilla/')) {
            return true; // real browsers all start with Mozilla/5.0
        }
        foreach (self::BOTS as $needle) {
            if (str_contains($ua, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{browser: string, os: string, device: string} */
    public static function parse(string $ua): array
    {
        return ['browser' => self::browser($ua), 'os' => self::os($ua), 'device' => self::device($ua)];
    }

    public static function browser(string $ua): string
    {
        // Order matters: most browsers also claim to be Chrome and Safari.
        $map = [
            'EdgA?/|Edg/|Edge/'       => 'Edge',
            'OPR/|Opera'              => 'Opera',
            'SamsungBrowser'          => 'Samsung Internet',
            'YaBrowser'               => 'Yandex Browser',
            'UCBrowser'               => 'UC Browser',
            'Vivaldi'                 => 'Vivaldi',
            'DuckDuckGo/'             => 'DuckDuckGo',
            'FBAN|FBAV|Instagram'     => 'In-app (Meta)',
            'Firefox/|FxiOS'          => 'Firefox',
            'CriOS|Chrome/|Chromium/' => 'Chrome',
            'Version/.*Safari/|Safari/' => 'Safari',
            'MSIE |Trident/'          => 'Internet Explorer',
        ];
        foreach ($map as $pattern => $name) {
            if (preg_match('#' . $pattern . '#', $ua)) {
                return $name;
            }
        }

        return 'Other';
    }

    public static function os(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/iPad|iPhone|iPod/', $ua)          => str_contains($ua, 'iPad') ? 'iPadOS' : 'iOS',
            str_contains($ua, 'Android')                         => 'Android',
            str_contains($ua, 'CrOS')                            => 'ChromeOS',
            str_contains($ua, 'Windows')                         => 'Windows',
            str_contains($ua, 'Mac OS X'), str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux'), str_contains($ua, 'X11') => 'Linux',
            default                                              => 'Other',
        };
    }

    public static function device(string $ua): string
    {
        if (preg_match('/iPad|Tablet|PlayBook|Silk|Kindle/i', $ua) || (str_contains($ua, 'Android') && !str_contains($ua, 'Mobile'))) {
            return 'Tablet';
        }
        if (preg_match('/Mobi|iPhone|iPod|Windows Phone/i', $ua)) {
            return 'Mobile';
        }

        return 'Desktop';
    }

    /** Screen size class from the viewport width the script reports. */
    public static function screen(int $width): string
    {
        return match (true) {
            $width <= 0    => '',
            $width < 576   => 'Phone',
            $width < 992   => 'Tablet',
            $width < 1440  => 'Laptop',
            default        => 'Desktop',
        };
    }
}
