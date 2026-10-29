<?php

declare(strict_types=1);

namespace WynAnalytics;

/**
 * Hand-drawn SVG charts, rendered on the server so they work without JavaScript and in print.
 * wyn-analytics-admin.js adds the hover tooltip. Colors come from CSS variables (ink only:
 * status colors are reserved for state).
 */
final class Charts
{
    /**
     * Main line chart.
     *
     * @param array<string|int, ?float> $values bucket => value (null = no data)
     * @param array<string|int, string> $labels bucket => label
     */
    public static function line(array $values, array $labels, string $metric, string $title, string $id, int $width = 1100, int $height = 260): string
    {
        $padL = 52;
        $padR = 12;
        $padT = 14;
        $padB = 28;
        $w = $width - $padL - $padR;
        $h = $height - $padT - $padB;
        $keys = array_keys($values);
        $n = count($keys);
        $max = self::niceCeil(max(1.0, ...array_map(fn ($v) => (float) ($v ?? 0), array_values($values) ?: [0])), $metric);
        $x = fn (int $i) => round($padL + ($n <= 1 ? $w / 2 : $i / ($n - 1) * $w), 1);
        $y = fn (float $v) => round($padT + $h - $v / $max * $h, 1);

        $svg = '<svg class="wyn-chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-labelledby="' . $id . '" data-wyn-chart>';
        $svg .= '<title id="' . $id . '">' . self::e($title) . '</title>';

        for ($i = 0; $i <= 4; $i++) {
            $val = $max * $i / 4;
            $yy = $y($val);
            $svg .= '<line x1="' . $padL . '" x2="' . ($padL + $w) . '" y1="' . $yy . '" y2="' . $yy . '" class="wyn-grid"/>';
            $svg .= '<text x="' . ($padL - 10) . '" y="' . ($yy + 4) . '" class="wyn-axis" text-anchor="end">' . self::e(self::axis($val, $metric)) . '</text>';
        }

        // About 7 x-axis labels (4 on phones), whatever the number of buckets.
        $every = max(1, (int) ceil($n / ($width < 600 ? 4 : 7)));
        foreach ($keys as $i => $k) {
            if ($i % $every === 0 || $i === $n - 1 && $n <= 8) {
                $anchor = $n > 1 && $i === 0 ? 'start' : ($n > 1 && $i === $n - 1 ? 'end' : 'middle');
                $svg .= '<text x="' . $x($i) . '" y="' . ($height - 6) . '" class="wyn-axis" text-anchor="' . $anchor . '">' . self::e($labels[$k] ?? (string) $k) . '</text>';
            }
        }

        // Line and area, broken where there's no data (bounce rate of a day with no visits).
        $segments = [];
        $seg = [];
        foreach ($keys as $i => $k) {
            if ($values[$k] === null) {
                $segments[] = $seg;
                $seg = [];
                continue;
            }
            $seg[] = [$x($i), $y((float) $values[$k])];
        }
        $segments[] = $seg;
        $base = $padT + $h;
        foreach ($segments as $s) {
            if ($s === []) {
                continue;
            }
            $pts = implode(' ', array_map(fn ($p) => $p[0] . ',' . $p[1], $s));
            if (count($s) > 1) {
                $svg .= '<polygon class="wyn-area" points="' . $s[0][0] . ',' . $base . ' ' . $pts . ' ' . end($s)[0] . ',' . $base . '"/>';
                $svg .= '<polyline class="wyn-line" points="' . $pts . '"/>';
            } else {
                $svg .= '<circle class="wyn-point" cx="' . $s[0][0] . '" cy="' . $s[0][1] . '" r="4"/>';
            }
        }

        // Hover targets carry the data for the tooltip (and a <title> for browsers without JS).
        $slot = $n > 1 ? $w / ($n - 1) : $w;
        foreach ($keys as $i => $k) {
            $label = $labels[$k] ?? (string) $k;
            $text = Report::formatMetric($metric, $values[$k]);
            $svg .= '<rect class="wyn-hit" x="' . round($x($i) - $slot / 2, 1) . '" y="' . $padT . '" width="' . round($slot, 1) . '" height="' . $h . '"'
                . ' data-x="' . $x($i) . '" data-y="' . ($values[$k] === null ? $base : $y((float) $values[$k])) . '"'
                . ' data-label="' . self::e($label) . '" data-value="' . self::e($text) . '"><title>' . self::e($label . ': ' . $text) . '</title></rect>';
        }

        return $svg . '</svg>';
    }

    /** Small trend line for the sites list. */
    public static function sparkline(array $values, int $width = 160, int $height = 40): string
    {
        $values = array_values(array_map(fn ($v) => (float) ($v ?? 0), $values));
        $n = count($values);
        if ($n < 2) {
            return '';
        }
        $max = max(1.0, ...$values);
        $pts = [];
        foreach ($values as $i => $v) {
            $pts[] = round($i / ($n - 1) * ($width - 4) + 2, 1) . ',' . round($height - 3 - $v / $max * ($height - 8), 1);
        }
        $line = implode(' ', $pts);

        return '<svg class="wyn-spark" viewBox="0 0 ' . $width . ' ' . $height . '" width="' . $width . '" height="' . $height . '" aria-hidden="true" focusable="false">'
            . '<polygon class="wyn-area" points="2,' . $height . ' ' . $line . ' ' . ($width - 2) . ',' . $height . '"/>'
            . '<polyline class="wyn-line" points="' . $line . '"/></svg>';
    }

    private static function niceCeil(float $v, string $metric): float
    {
        if ($metric === 'bounce_rate') {
            return 100.0;
        }
        $v *= 1.1;
        $mag = 10 ** floor(log10($v));
        foreach ([1, 2, 2.5, 5, 10] as $step) {
            if ($v <= $step * $mag) {
                return max(4.0, $step * $mag);
            }
        }

        return 10 * $mag;
    }

    private static function axis(float $v, string $metric): string
    {
        return match ($metric) {
            'bounce_rate'    => round($v) . '%',
            'visit_duration' => Format::duration($v),
            default          => Format::compact((int) round($v)),
        };
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
