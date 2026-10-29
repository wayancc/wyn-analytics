<?php

declare(strict_types=1);

namespace WynAnalytics;

final class Format
{
    /** 1234 → "1.2k", 1250000 → "1.3M". Exact below 10,000. */
    public static function compact(int|float $n): string
    {
        $n = (float) $n;
        if (abs($n) < 10_000) {
            return number_format($n);
        }
        foreach (['B' => 1e9, 'M' => 1e6, 'k' => 1e3] as $suffix => $size) {
            // Move up a unit a little early, so 999,950 is "1M" rather than "1,000k".
            if (abs($n) >= $size * 0.9995) {
                $v = $n / $size;
                $s = number_format($v, abs($v) < 100 ? 1 : 0);

                // "1.0k" → "1k", but the zeros in "120k" stay.
                return (str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s) . $suffix;
            }
        }

        return number_format($n);
    }

    /** Visit duration: "48s", "3m 05s", "1h 02m". */
    public static function duration(int|float $seconds): string
    {
        $s = (int) round(max(0, $seconds));
        if ($s < 60) {
            return $s . 's';
        }
        if ($s < 3600) {
            return intdiv($s, 60) . 'm ' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) . 's';
        }

        return intdiv($s, 3600) . 'h ' . str_pad((string) intdiv($s % 3600, 60), 2, '0', STR_PAD_LEFT) . 'm';
    }

    public static function percent(?float $value): string
    {
        return $value === null ? '—' : (string) round($value) . '%';
    }

    /** Relative change in percent, or null when there's nothing to compare against. */
    public static function change(float $now, float $before): ?float
    {
        if ($before == 0.0) {
            return $now == 0.0 ? 0.0 : null;
        }

        return ($now - $before) / $before * 100;
    }

    public static function ago(int $ts, ?int $now = null): string
    {
        $diff = max(0, ($now ?? time()) - $ts);
        if ($diff < 10) {
            return 'just now';
        }
        foreach (['d' => 86400, 'h' => 3600, 'm' => 60] as $label => $size) {
            if ($diff >= $size) {
                return intdiv($diff, $size) . $label . ' ago';
            }
        }

        return $diff . 's ago';
    }
}
