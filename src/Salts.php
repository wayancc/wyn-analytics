<?php

declare(strict_types=1);

namespace WynAnalytics;

/**
 * Daily salts for visitor IDs.
 *
 * A visitor ID is HMAC(site + IP + browser string, salt of the day). Each calendar date
 * gets its own random salt, and salts are deleted once that date is over everywhere on
 * Earth. After that, nobody (including whoever runs the server) can recompute an old ID
 * from an IP address or connect one day's visitors to the next.
 */
final class Salts
{
    /** @var array<string, string> */
    private array $cache = [];

    /** @param \Closure(): int $clock */
    public function __construct(private readonly Database $db, private readonly \Closure $clock)
    {
    }

    public function forDay(string $day): string
    {
        if (isset($this->cache[$day])) {
            return $this->cache[$day];
        }
        $salt = $this->db->value('SELECT salt FROM wyn_salts WHERE day = ?', [$day]);
        if ($salt === null) {
            $this->db->query('INSERT OR IGNORE INTO wyn_salts (day, salt) VALUES (?, ?)', [$day, bin2hex(random_bytes(32))]);
            // A new date has begun somewhere: also drop salts that are no longer needed,
            // so old salts disappear even on installs where the scheduled job never runs.
            $this->rotate();
            // Re-read: another request may have inserted it a moment earlier.
            $salt = (string) $this->db->value('SELECT salt FROM wyn_salts WHERE day = ?', [$day]);
        }

        return $this->cache[$day] = (string) $salt;
    }

    /**
     * Deletes salts for dates that have ended in every time zone. Local dates run from
     * UTC-12 to UTC+14, so anything older than yesterday (UTC) is over everywhere.
     * Runs on every request, so it reads first and only takes the write lock when needed.
     */
    public function rotate(?int $now = null): int
    {
        $cutoff = gmdate('Y-m-d', ($now ?? ($this->clock)()) - 86400);
        if ($this->db->value('SELECT 1 FROM wyn_salts WHERE day < ? LIMIT 1', [$cutoff]) === null) {
            return 0;
        }
        $this->cache = [];

        return $this->db->query('DELETE FROM wyn_salts WHERE day < ?', [$cutoff])->rowCount();
    }

    /** Daily visitor ID: 16 hex characters. */
    public function visitorId(int $siteId, string $ip, string $userAgent, string $day): string
    {
        return substr(hash_hmac('sha256', $siteId . "\n" . $ip . "\n" . $userAgent, $this->forDay($day)), 0, 16);
    }
}
