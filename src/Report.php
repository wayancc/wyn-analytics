<?php

declare(strict_types=1);

namespace WynAnalytics;

/**
 * One period of stats for one site, shaped for the dashboard, the public page and the API.
 */
final class Report
{
    public const METRICS = [
        'visitors'       => 'Unique visitors',
        'visits'         => 'Visits',
        'pageviews'      => 'Pageviews',
        'bounce_rate'    => 'Bounce rate',
        'visit_duration' => 'Visit duration',
    ];

    /** Breakdown panels: dim => label. */
    public const DIMS = [
        'page' => 'Top pages', 'entry' => 'Entry pages', 'exit' => 'Exit pages',
        'source' => 'Sources', 'referrer' => 'Referrers', 'utm_source' => 'UTM source', 'utm_medium' => 'UTM medium', 'utm_campaign' => 'UTM campaign',
        'country' => 'Countries',
        'device' => 'Devices', 'browser' => 'Browsers', 'os' => 'Operating systems', 'screen' => 'Screen sizes',
        'event' => 'Events',
    ];

    public array $totals = [];
    public array $previous = [];
    public array $series = [];
    public array $labels = [];
    public array $breakdowns = [];
    public array $goals = [];
    public array $props = [];
    /** Chart buckets that haven't started yet (later hours of today). */
    public array $future = [];
    public int $now = 0;
    public bool $partial = false;

    public function __construct(
        public readonly array $site,
        public readonly Period $period,
        public readonly array $filters = [],
    ) {
    }

    /**
     * @param list<string> $dims breakdowns to include
     */
    public static function build(App $app, array $site, Period $period, array $filters, array $dims, int $limit = 10): self
    {
        $r = new self($site, $period, $filters);
        $stats = $app->stats;
        $r->totals = Stats::summary($stats->totals($site, $period->from, $period->to, $filters));
        $r->previous = Stats::summary($stats->totals($site, $period->prevFrom, $period->prevTo, $filters, $period->prevCutoff));
        $r->series = $stats->series($site, $period, $filters);
        $r->labels = $period->buckets();
        if ($period->interval === 'hour') {
            foreach ($period->hourBoundaries() as $i => $ts) {
                if ($ts > $app->now()) {
                    $r->future[$i] = true;
                }
            }
        }
        foreach ($dims as $dim) {
            $r->breakdowns[$dim] = $stats->breakdown($site, $period->from, $period->to, $dim, $filters, $limit);
        }
        if (in_array('event', $dims, true)) {
            $r->props = self::groupProps($stats->breakdown($site, $period->from, $period->to, 'prop', $filters, 200));
        }
        $r->goals = $stats->goals($site, $period->from, $period->to, $filters);
        $r->now = $stats->now($site, $app->now());
        $r->partial = $stats->filteredPartially($site, $period->from, $filters, $app->settings->retentionDays(), $app->now());

        return $r;
    }

    /** prop rows "Event\x1Fkey\x1Fvalue" → [event => [key => [value => metrics]]], top 10 values each. */
    private static function groupProps(array $rows): array
    {
        $out = [];
        foreach ($rows as $key => $m) {
            $parts = explode("\x1F", (string) $key, 3);
            if (count($parts) === 3 && count($out[$parts[0]][$parts[1]] ?? []) < 10) {
                $out[$parts[0]][$parts[1]][$parts[2]] = $m;
            }
        }

        return $out;
    }

    public function change(string $metric): ?float
    {
        $now = $this->totals[$metric] ?? null;
        $before = $this->previous[$metric] ?? null;
        if ($now === null || $before === null) {
            return null;
        }
        // Bounce rate is already a percentage: show the difference in points.
        if ($metric === 'bounce_rate') {
            return $now - $before;
        }

        return Format::change((float) $now, (float) $before);
    }

    /** Is this change good news? Fewer bounces is good; for everything else, more is good. */
    public static function isGood(string $metric, float $change): bool
    {
        return $metric === 'bounce_rate' ? $change < 0 : $change > 0;
    }

    public static function formatMetric(string $metric, mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($metric) {
            'bounce_rate'     => Format::percent((float) $value),
            'visit_duration'  => Format::duration((float) $value),
            'views_per_visit' => number_format((float) $value, 1),
            default           => Format::compact((int) $value),
        };
    }

    /** Value of a metric in one chart bucket. */
    public static function bucketValue(array $m, string $metric): ?float
    {
        $visits = $m['visits'];

        return match ($metric) {
            'bounce_rate'    => $visits > 0 ? $m['bounces'] / $visits * 100 : null,
            'visit_duration' => $visits > 0 ? $m['duration'] / $visits : null,
            default          => (float) ($m[$metric] ?? 0),
        };
    }

    /** Machine-friendly version for /api/stats.json. */
    public function toArray(): array
    {
        $changes = [];
        foreach (array_keys(self::METRICS) as $m) {
            $c = $this->change($m);
            $changes[$m] = $c === null ? null : round($c, 1);
        }
        $series = [];
        foreach ($this->series as $key => $m) {
            if (isset($this->future[$key])) {
                continue;
            }
            $series[] = ['bucket' => (string) $key, 'label' => $this->labels[$key] ?? (string) $key] + $m + [
                'bounce_rate'    => self::round(self::bucketValue($m, 'bounce_rate')),
                'visit_duration' => self::round(self::bucketValue($m, 'visit_duration')),
            ];
        }
        $breakdowns = [];
        foreach ($this->breakdowns as $dim => $rows) {
            $breakdowns[$dim] = [];
            foreach ($rows as $value => $m) {
                $row = ['value' => (string) $value, 'visitors' => $m['visitors']];
                $row += match ($dim) {
                    'page'  => ['pageviews' => $m['pageviews'], 'avg_time_on_page' => $m['pageviews'] > 0 ? round($m['duration'] / $m['pageviews']) : null],
                    'event' => ['events' => $m['events']],
                    default => ['visits' => $m['visits'], 'bounce_rate' => self::round(self::bucketValue($m, 'bounce_rate'))],
                };
                $breakdowns[$dim][] = $row;
            }
        }

        return [
            'site'       => ['domain' => $this->site['domain'], 'name' => $this->site['name'], 'timezone' => $this->site['timezone']],
            'period'     => [
                'range' => $this->period->key, 'from' => $this->period->from, 'to' => $this->period->to,
                'label' => $this->period->label, 'interval' => $this->period->interval,
                'previous' => ['from' => $this->period->prevFrom, 'to' => $this->period->prevTo],
            ],
            'filters'    => (object) $this->filters,
            'totals'     => self::roundAll($this->totals),
            'previous'   => self::roundAll($this->previous),
            'change'     => $changes,
            'now'        => $this->now,
            'series'     => $series,
            'breakdowns' => $breakdowns,
            'goals'      => array_map(fn ($g) => [
                'goal' => $g['label'], 'type' => $g['goal']['kind'], 'visitors' => $g['visitors'],
                'completions' => $g['completions'], 'conversion_rate' => round($g['rate'], 2),
            ], $this->goals),
        ];
    }

    private static function round(?float $v): ?float
    {
        return $v === null ? null : round($v, 1);
    }

    private static function roundAll(array $a): array
    {
        return array_map(fn ($v) => is_float($v) ? round($v, 1) : $v, $a);
    }
}
