<?php

declare(strict_types=1);

namespace WynAnalytics;

/**
 * Answers every dashboard, API and export question.
 *
 * Closed days come from the daily rollups (kept forever); days that aren't rolled up yet
 * (today, usually) come from raw visits. The two sets never overlap, so their numbers add up.
 * Unique visitors add up across days too, because visitor IDs change every day.
 *
 * Filters segment visits ("visits from Instagram", "visits that saw /pricing") and need raw
 * data, so filtered views cover the retention period (30 days by default).
 */
final class Stats
{
    /** Visit attribute dimensions and their columns. */
    public const VISIT_DIMS = [
        'entry'        => 'v.entry_path',
        'exit'         => 'v.exit_path',
        'source'       => 'v.source',
        'referrer'     => 'v.referrer',
        'utm_source'   => 'v.utm_source',
        'utm_medium'   => 'v.utm_medium',
        'utm_campaign' => 'v.utm_campaign',
        'country'      => 'v.country',
        'device'       => 'v.device',
        'browser'      => 'v.browser',
        'os'           => 'v.os',
        'screen'       => 'v.screen',
    ];

    /** Dimensions that can be filtered on, with labels. */
    public const FILTERS = [
        'page' => 'Page', 'entry' => 'Entry page', 'exit' => 'Exit page', 'source' => 'Source', 'referrer' => 'Referrer',
        'utm_source' => 'UTM source', 'utm_medium' => 'UTM medium', 'utm_campaign' => 'UTM campaign', 'country' => 'Country',
        'device' => 'Device', 'browser' => 'Browser', 'os' => 'OS', 'screen' => 'Screen', 'event' => 'Event', 'goal' => 'Goal',
    ];

    public const ROLLUP_DIMS = ['', 'page', 'event', 'prop', 'goal', ...['entry', 'exit', 'source', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'country', 'device', 'browser', 'os', 'screen']];

    private const ZERO = ['visitors' => 0, 'visits' => 0, 'pageviews' => 0, 'bounces' => 0, 'duration' => 0, 'events' => 0];
    private const VISIT_METRICS = 'COUNT(DISTINCT v.visitor) AS visitors, COUNT(*) AS visits, SUM(v.pageviews) AS pageviews,
        SUM(CASE WHEN v.pageviews <= 1 AND v.events = 0 THEN 1 ELSE 0 END) AS bounces,
        SUM(MIN(14400, MAX(v.last_at - v.started_at, v.engaged_ms / 1000))) AS duration, SUM(v.events) AS events';

    public function __construct(private readonly Database $db, private readonly Sites $sites)
    {
    }

    // ------------------------------------------------------------------ Public API

    /** Totals for a period: visitors, visits, pageviews, bounces, duration, events. */
    /**
     * With $cutoff, the last day only counts visits that started by that moment ("so far").
     * That day is read from raw data when it's still there, otherwise from its rollup.
     */
    public function totals(array $site, string $from, string $to, array $filters = [], ?int $cutoff = null): array
    {
        if ($cutoff === null) {
            return $this->aggregate($site, $from, $to, $filters, [''])[''][''] ?? self::ZERO;
        }
        $hasRaw = $this->db->value('SELECT 1 FROM wyn_visits WHERE site_id = ? AND day = ? LIMIT 1', [$site['id'], $to]) !== null;
        if (!$hasRaw) {
            return $this->totals($site, $from, $to, $filters);
        }
        $last = $this->raw($site, $to, $to, $filters, [''], false, $cutoff)[''][''] ?? self::ZERO;
        if ($from === $to) {
            return $last;
        }
        $before = (new \DateTimeImmutable($to, new \DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');

        return self::add($this->totals($site, $from, $before, $filters), $last);
    }

    /** Top values of one dimension, biggest first. */
    public function breakdown(array $site, string $from, string $to, string $dim, array $filters = [], int $limit = 10): array
    {
        $rows = $this->aggregate($site, $from, $to, $filters, [$dim])[$dim] ?? [];
        unset($rows['(other)']);
        uasort($rows, fn ($a, $b) => [$b['visitors'], $b['pageviews'], $b['events']] <=> [$a['visitors'], $a['pageviews'], $a['events']]);

        return array_slice($rows, 0, $limit, true);
    }

    /** Chart series: [bucket key => metrics]. */
    public function series(array $site, Period $period, array $filters = []): array
    {
        $buckets = array_fill_keys(array_keys($period->buckets()), self::ZERO);

        if ($period->interval === 'hour') {
            $bounds = $period->hourBoundaries();
            $case = 'CASE';
            foreach (array_slice($bounds, 1) as $i => $ts) {
                $case .= ' WHEN v.started_at < ' . (int) $ts . ' THEN ' . $i;
            }
            $case .= ' ELSE ' . (count($bounds) - 1) . ' END';
            [$where, $params] = $this->visitWhere($site, $period->from, $period->to, $filters, false);
            foreach ($this->db->all("SELECT {$case} AS bucket, " . self::VISIT_METRICS . " FROM wyn_visits v WHERE {$where} GROUP BY bucket", $params) as $row) {
                $buckets[(int) $row['bucket']] = self::metrics($row);
            }

            return $buckets;
        }

        $key = $period->interval === 'month' ? 'substr(%s, 1, 7)' : '%s';
        $add = function (string $bucket, array $row) use (&$buckets) {
            if (isset($buckets[$bucket])) {
                $buckets[$bucket] = self::add($buckets[$bucket], self::metrics($row));
            }
        };

        $raw = $filters !== [];
        if (!$raw) {
            $rollKey = sprintf($key, 'day');
            $rows = $this->db->all(
                "SELECT {$rollKey} AS bucket, SUM(visitors) AS visitors, SUM(visits) AS visits, SUM(pageviews) AS pageviews, SUM(bounces) AS bounces,
                 SUM(duration) AS duration, SUM(events) AS events FROM wyn_daily WHERE site_id = ? AND dim = '' AND day BETWEEN ? AND ? GROUP BY bucket",
                [$site['id'], $period->from, $period->to],
            );
            foreach ($rows as $row) {
                $add((string) $row['bucket'], $row);
            }
        }
        [$where, $params] = $this->visitWhere($site, $period->from, $period->to, $filters, !$raw);
        $rawKey = sprintf($key, 'v.day');
        foreach ($this->db->all("SELECT {$rawKey} AS bucket, " . self::VISIT_METRICS . " FROM wyn_visits v WHERE {$where} GROUP BY bucket", $params) as $row) {
            $add((string) $row['bucket'], $row);
        }

        return $buckets;
    }

    /** Goals with conversions: visitors who completed it, completions, and conversion rate. */
    public function goals(array $site, string $from, string $to, array $filters = []): array
    {
        $goals = $this->sites->goals((int) $site['id']);
        if ($goals === []) {
            return [];
        }
        $agg = $this->aggregate($site, $from, $to, $filters, ['', 'goal']);
        $total = max(1, (int) ($agg['']['']['visitors'] ?? 0));
        $rows = $agg['goal'] ?? [];

        // Days rolled up before a goal existed and no longer in raw data: count exact pages and
        // events from the page and event rollups. Wildcard goals can only be estimated there.
        $fallback = $filters === [];
        $out = [];
        foreach ($goals as $g) {
            $row = $rows[(string) $g['id']] ?? self::ZERO;
            $approx = false;
            if ($fallback) {
                [$dim, $op, $col] = $g['kind'] === 'event' ? ['event', '=', 'events'] : ['page', str_contains($g['value'], '*') ? 'GLOB' : '=', 'pageviews'];
                $old = $this->db->one(
                    "SELECT SUM(d.visitors) AS visitors, SUM(d.{$col}) AS n FROM wyn_daily d JOIN wyn_rollups r ON r.site_id = d.site_id AND r.day = d.day
                     WHERE d.site_id = ? AND d.dim = ? AND d.value {$op} ? AND d.day BETWEEN ? AND ? AND r.rolled_at < ?",
                    [$site['id'], $dim, $op === 'GLOB' ? Sites::glob($g['value']) : $g['value'], $from, $to, (int) $g['created_at']],
                );
                if ((int) ($old['visitors'] ?? 0) > 0) {
                    $row['visitors'] += (int) $old['visitors'];
                    $row['events'] += (int) $old['n'];
                    $approx = $op === 'GLOB';
                }
            }
            $out[] = [
                'goal'        => $g,
                'label'       => Sites::goalLabel($g),
                'visitors'    => (int) $row['visitors'],
                'completions' => (int) $row['events'],
                'rate'        => $row['visitors'] / $total * 100,
                'approx'      => $approx,
            ];
        }
        usort($out, fn ($a, $b) => $b['visitors'] <=> $a['visitors']);

        return $out;
    }

    /** Visitors active in the last 5 minutes. */
    public function now(array $site, int $now): int
    {
        return (int) $this->db->value('SELECT COUNT(DISTINCT visitor) FROM wyn_visits WHERE site_id = ? AND last_at >= ?', [$site['id'], $now - 300]);
    }

    /** Derived numbers shown on the dashboard. */
    public static function summary(array $t): array
    {
        $visits = max(0, (int) $t['visits']);

        return [
            'visitors'        => (int) $t['visitors'],
            'visits'          => $visits,
            'pageviews'       => (int) $t['pageviews'],
            'bounce_rate'     => $visits > 0 ? $t['bounces'] / $visits * 100 : null,
            'visit_duration'  => $visits > 0 ? $t['duration'] / $visits : null,
            'views_per_visit' => $visits > 0 ? $t['pageviews'] / $visits : null,
            'events'          => (int) $t['events'],
        ];
    }

    /** Is part of a filtered period older than the raw data? */
    public function filteredPartially(array $site, string $from, array $filters, int $retentionDays, int $now): bool
    {
        if ($filters === []) {
            return false;
        }
        $cutoff = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone($site['timezone']))->modify("-{$retentionDays} days")->format('Y-m-d');

        return $from < $cutoff && $this->db->value('SELECT 1 FROM wyn_rollups WHERE site_id = ? AND day >= ? AND day < ? LIMIT 1', [$site['id'], $from, $cutoff]) !== null;
    }

    // ------------------------------------------------------------------ Aggregation

    /**
     * @param list<string> $dims '' for totals, plus any of ROLLUP_DIMS
     * @return array<string, array<string, array>> dim => value => metrics
     */
    public function aggregate(array $site, string $from, string $to, array $filters, array $dims, ?int $cutoff = null): array
    {
        // Rolled-up days can only answer unfiltered questions. A cutoff needs exact timestamps.
        $useRollups = $filters === [] && $cutoff === null;
        $out = array_fill_keys($dims, []);

        if ($useRollups) {
            $in = implode(',', array_fill(0, count($dims), '?'));
            $rows = $this->db->all(
                "SELECT dim, value, SUM(visitors) AS visitors, SUM(visits) AS visits, SUM(pageviews) AS pageviews, SUM(bounces) AS bounces,
                 SUM(duration) AS duration, SUM(events) AS events FROM wyn_daily WHERE site_id = ? AND day BETWEEN ? AND ? AND dim IN ({$in}) GROUP BY dim, value",
                [$site['id'], $from, $to, ...$dims],
            );
            foreach ($rows as $row) {
                $out[$row['dim']][$row['value']] = self::metrics($row);
            }
        }

        foreach ($this->raw($site, $from, $to, $filters, $dims, $useRollups, $cutoff) as $dim => $values) {
            foreach ($values as $value => $metrics) {
                $out[$dim][$value] = isset($out[$dim][$value]) ? self::add($out[$dim][$value], $metrics) : $metrics;
            }
        }

        return $out;
    }

    /**
     * Aggregates raw visits and events. With $skipRolled, days that already have a rollup are left out.
     *
     * @return array<string, array<string, array>>
     */
    public function raw(array $site, string $from, string $to, array $filters, array $dims, bool $skipRolled = false, ?int $cutoff = null): array
    {
        [$where, $params] = $this->visitWhere($site, $from, $to, $filters, $skipRolled, $cutoff);
        $out = [];

        foreach ($dims as $dim) {
            if ($dim === '') {
                $row = $this->db->one('SELECT ' . self::VISIT_METRICS . " FROM wyn_visits v WHERE {$where}", $params);
                $out[''] = (int) ($row['visits'] ?? 0) > 0 ? ['' => self::metrics($row)] : [];
            } elseif (isset(self::VISIT_DIMS[$dim])) {
                $col = self::VISIT_DIMS[$dim];
                $out[$dim] = [];
                foreach ($this->db->all("SELECT {$col} AS value, " . self::VISIT_METRICS . " FROM wyn_visits v WHERE {$where} GROUP BY {$col}", $params) as $row) {
                    $out[$dim][(string) $row['value']] = self::metrics($row);
                }
            } elseif ($dim === 'page' || $dim === 'event') {
                $type = $dim === 'page' ? 'pageview' : 'event';
                $col = $dim === 'page' ? 'e.path' : 'e.name';
                $out[$dim] = [];
                $rows = $this->db->all(
                    "SELECT {$col} AS value, COUNT(DISTINCT v.visitor) AS visitors, COUNT(DISTINCT v.id) AS visits,
                     SUM(e.type = 'pageview') AS pageviews, 0 AS bounces, SUM(e.engaged_ms) / 1000 AS duration, SUM(e.type = 'event') AS events
                     FROM wyn_events e JOIN wyn_visits v ON v.id = e.visit_id WHERE e.type = '{$type}' AND {$where} GROUP BY {$col}",
                    $params,
                );
                foreach ($rows as $row) {
                    $out[$dim][(string) $row['value']] = self::metrics($row);
                }
            } elseif ($dim === 'goal') {
                $out['goal'] = [];
                foreach ($this->sites->goals((int) $site['id']) as $g) {
                    [$cond, $cp] = Sites::goalCondition($g);
                    $row = $this->db->one(
                        "SELECT COUNT(DISTINCT v.visitor) AS visitors, COUNT(DISTINCT v.id) AS visits, COUNT(*) AS events
                         FROM wyn_events e JOIN wyn_visits v ON v.id = e.visit_id WHERE {$cond} AND {$where}",
                        [...$cp, ...$params],
                    );
                    if ((int) $row['events'] > 0) {
                        $out['goal'][(string) $g['id']] = self::metrics($row);
                    }
                }
            } elseif ($dim === 'prop') {
                // Event properties: counted in PHP, because JSON functions vary between SQLite builds.
                $out['prop'] = [];
                $seen = [];
                $rows = $this->db->query(
                    "SELECT e.name, e.props, v.visitor FROM wyn_events e JOIN wyn_visits v ON v.id = e.visit_id
                     WHERE e.type = 'event' AND e.props IS NOT NULL AND {$where} LIMIT 500000",
                    $params,
                );
                while ($row = $rows->fetch()) {
                    foreach (json_decode((string) $row['props'], true) ?: [] as $k => $val) {
                        $key = $row['name'] . "\x1F" . $k . "\x1F" . $val;
                        $out['prop'][$key] ??= self::ZERO;
                        $out['prop'][$key]['events']++;
                        if (!isset($seen[$key][$row['visitor']])) {
                            $seen[$key][$row['visitor']] = true;
                            $out['prop'][$key]['visitors']++;
                        }
                    }
                }
            }
        }

        return $out;
    }

    /** @return array{0: string, 1: array} WHERE clause over wyn_visits v */
    private function visitWhere(array $site, string $from, string $to, array $filters, bool $skipRolled, ?int $cutoff = null): array
    {
        $where = 'v.site_id = ? AND v.day BETWEEN ? AND ?';
        $params = [(int) $site['id'], $from, $to];
        if ($skipRolled) {
            $where .= ' AND v.day NOT IN (SELECT day FROM wyn_rollups WHERE site_id = ? AND day BETWEEN ? AND ?)';
            array_push($params, (int) $site['id'], $from, $to);
        }
        if ($cutoff !== null) {
            $where .= ' AND v.started_at <= ?';
            $params[] = $cutoff;
        }

        foreach ($filters as $dim => $value) {
            $value = (string) $value;
            if (isset(self::VISIT_DIMS[$dim])) {
                $where .= ' AND ' . self::VISIT_DIMS[$dim] . ' = ?';
                $params[] = $value;
            } elseif ($dim === 'page') {
                $where .= " AND EXISTS (SELECT 1 FROM wyn_events f WHERE f.visit_id = v.id AND f.type = 'pageview' AND f.path = ?)";
                $params[] = $value;
            } elseif ($dim === 'event') {
                $where .= " AND EXISTS (SELECT 1 FROM wyn_events f WHERE f.visit_id = v.id AND f.type = 'event' AND f.name = ?)";
                $params[] = $value;
            } elseif ($dim === 'goal') {
                $goal = $this->db->one('SELECT * FROM wyn_goals WHERE id = ? AND site_id = ?', [(int) $value, $site['id']]);
                if ($goal === null) {
                    $where .= ' AND 0';
                    continue;
                }
                [$cond, $cp] = Sites::goalCondition($goal, 'f');
                $where .= " AND EXISTS (SELECT 1 FROM wyn_events f WHERE f.visit_id = v.id AND {$cond})";
                array_push($params, ...$cp);
            }
        }

        return [$where, $params];
    }

    /** Keeps only filters the dashboard knows, as strings. */
    public static function cleanFilters(array $query): array
    {
        $out = [];
        foreach (array_keys(self::FILTERS) as $dim) {
            if (isset($query['f_' . $dim]) && is_string($query['f_' . $dim])) {
                $out[$dim] = mb_substr($query['f_' . $dim], 0, 300);
            }
        }

        return $out;
    }

    private static function metrics(array $row): array
    {
        $out = [];
        foreach (self::ZERO as $k => $_) {
            $out[$k] = (int) round((float) ($row[$k] ?? 0));
        }

        return $out;
    }

    private static function add(array $a, array $b): array
    {
        foreach ($a as $k => $v) {
            $a[$k] = $v + ($b[$k] ?? 0);
        }

        return $a;
    }
}
