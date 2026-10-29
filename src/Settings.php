<?php

declare(strict_types=1);

namespace WynAnalytics;

/**
 * Key/value settings edited from the dashboard.
 */
final class Settings
{
    public const DEFAULTS = [
        'site_name'      => 'Wyn Analytics',
        'theme'          => 'wayan',  // wayan (bold, based on wayan.cc) | neutral
        'retention_days' => '30',     // raw events and visits; daily rollups are kept forever
        'cron_token'     => '',       // secret part of the web-cron URL
        'pepper'         => '',       // install secret for hashing excluded IPs and sign-in throttling
        'last_run_at'    => '0',
    ];

    private ?array $cache = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $key): string
    {
        $this->cache ??= array_column($this->db->all('SELECT key, value FROM wyn_settings'), 'value', 'key');

        return (string) ($this->cache[$key] ?? self::DEFAULTS[$key] ?? '');
    }

    public function set(string $key, string $value): void
    {
        $this->db->query('INSERT INTO wyn_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$key, $value]);
        if ($this->cache !== null) {
            $this->cache[$key] = $value;
        }
    }

    public function forget(): void
    {
        $this->cache = null;
    }

    public function retentionDays(): int
    {
        return max(1, min(3650, (int) $this->get('retention_days')));
    }

    /** Random secret created on first use. */
    public function secret(string $key): string
    {
        $value = $this->get($key);
        if ($value === '') {
            $value = bin2hex(random_bytes(16));
            $this->set($key, $value);
        }

        return $value;
    }

    /** Keyed hash for things that must be compared but never stored in the clear (IPs). */
    public function hash(string $value): string
    {
        return substr(hash_hmac('sha256', $value, $this->secret('pepper')), 0, 32);
    }
}
