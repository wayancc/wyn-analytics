<?php

declare(strict_types=1);

namespace WynAnalytics;

/**
 * Groups referring hosts into sources ("Google", "Instagram"), and normalises UTM values.
 */
final class Referrers
{
    /** Host pattern => source name. Patterns are matched against the host without "www.". */
    private const SOURCES = [
        // Specific hosts first: these would otherwise match the broader Google pattern.
        '/(^|\.)gemini\.google\.com$/'              => 'Gemini',
        '/(^|\.)(mail\.google\.com|outlook\.live\.com|outlook\.office\.com)$/' => 'Email',
        '/(^|\.)google\.[a-z.]+$/'                 => 'Google',
        '/(^|\.)bing\.com$/'                        => 'Bing',
        '/(^|\.)duckduckgo\.com$/'                  => 'DuckDuckGo',
        '/(^|\.)search\.yahoo\.[a-z.]+$|^yahoo\.[a-z.]+$/' => 'Yahoo',
        '/(^|\.)ecosia\.org$/'                      => 'Ecosia',
        '/(^|\.)baidu\.com$/'                       => 'Baidu',
        '/(^|\.)yandex\.[a-z.]+$|^ya\.ru$/'         => 'Yandex',
        '/(^|\.)naver\.com$/'                       => 'Naver',
        '/(^|\.)search\.brave\.com$/'               => 'Brave Search',
        '/(^|\.)qwant\.com$/'                       => 'Qwant',
        '/(^|\.)(facebook\.com|fb\.me|fb\.com)$/'   => 'Facebook',
        '/(^|\.)instagram\.com$/'                   => 'Instagram',
        '/(^|\.)threads\.(net|com)$/'               => 'Threads',
        '/^(t\.co|twitter\.com|x\.com|mobile\.twitter\.com)$/' => 'X (Twitter)',
        '/(^|\.)(linkedin\.com|lnkd\.in)$/'         => 'LinkedIn',
        '/(^|\.)reddit\.com$|^redd\.it$/'           => 'Reddit',
        '/(^|\.)(youtube\.com|youtu\.be)$/'         => 'YouTube',
        '/(^|\.)(pinterest\.[a-z.]+|pin\.it)$/'     => 'Pinterest',
        '/(^|\.)tiktok\.com$/'                      => 'TikTok',
        '/(^|\.)bsky\.app$/'                        => 'Bluesky',
        '/(^|\.)mastodon\.[a-z.]+$/'                => 'Mastodon',
        '/^news\.ycombinator\.com$/'                => 'Hacker News',
        '/(^|\.)producthunt\.com$/'                 => 'Product Hunt',
        '/(^|\.)github\.com$/'                      => 'GitHub',
        '/(^|\.)(wa\.me|whatsapp\.com)$/'           => 'WhatsApp',
        '/(^|\.)(t\.me|telegram\.org)$/'            => 'Telegram',
        '/(^|\.)(chatgpt\.com|chat\.openai\.com)$/' => 'ChatGPT',
        '/(^|\.)perplexity\.ai$/'                   => 'Perplexity',
        '/(^|\.)claude\.ai$/'                       => 'Claude',
        '/(^|\.)copilot\.microsoft\.com$/'          => 'Copilot',
        '/(^|\.)(tripadvisor\.[a-z.]+)$/'           => 'Tripadvisor',
        '/(^|\.)(booking\.com)$/'                   => 'Booking.com',
        '/(^|\.)(airbnb\.[a-z.]+)$/'                => 'Airbnb',
    ];

    /** utm_source shorthands people use in links. */
    private const UTM_ALIASES = [
        'ig' => 'Instagram', 'instagram' => 'Instagram', 'fb' => 'Facebook', 'facebook' => 'Facebook',
        'tw' => 'X (Twitter)', 'twitter' => 'X (Twitter)', 'x' => 'X (Twitter)', 'li' => 'LinkedIn', 'linkedin' => 'LinkedIn',
        'google' => 'Google', 'yt' => 'YouTube', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'wa' => 'WhatsApp',
        'whatsapp' => 'WhatsApp', 'newsletter' => 'Newsletter', 'email' => 'Email', 'chatgpt.com' => 'ChatGPT',
    ];

    /** Host of a URL, lower case, without "www.". '' when it isn't a web URL. */
    public static function host(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return '';
        }
        $host = strtolower((string) ($parts['host'] ?? ''));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** Source name for a referring host. Unknown hosts are their own source. */
    public static function source(string $host): string
    {
        if ($host === '') {
            return '';
        }
        foreach (self::SOURCES as $pattern => $name) {
            if (preg_match($pattern, $host)) {
                return $name;
            }
        }
        if (str_starts_with($host, 'android-app://') || str_contains($host, 'googlequicksearchbox')) {
            return 'Google';
        }

        return $host;
    }

    /** Source from a ?utm_source= / ?ref= value. */
    public static function sourceFromUtm(string $value): string
    {
        $value = self::clean($value);
        if ($value === '') {
            return '';
        }
        $key = strtolower($value);
        if (isset(self::UTM_ALIASES[$key])) {
            return self::UTM_ALIASES[$key];
        }
        // "google.com" style values: treat like a referring host.
        if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $key)) {
            return self::source(str_starts_with($key, 'www.') ? substr($key, 4) : $key);
        }

        return $value;
    }

    /** Trimmed, single-line, at most 100 characters. */
    public static function clean(string $value): string
    {
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

        return mb_substr($value, 0, 100);
    }
}
