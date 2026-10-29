<?php

declare(strict_types=1);

namespace WynAnalytics;

/**
 * Daily rollups and retention.
 *
 * A day is rolled up once it has ended in the site's time zone (plus an hour, for late
 * engagement pings). Rollups are kept forever; raw visits and events are deleted after the
 * retention period, but only for days that have been rolled up.
 */
final class Rollup
{
    private const MAX_VALUES = 1000;    // per dimension per day; the long tail is folded into "(other)"
    private const GRACE = 3600;

    public function __construct(
        private readonly Database $db,
        private readonly Stats $stats,
        private readonly Salts $salts,
        private readonly Settings $settings,
    ) {
    }

    /** Rolls up every closed day that isn't yet, for all sites, then prunes. */
    public function run(int $now, ?float $budgetSeconds = null): array
    {
        $rolled = 0;
        $start = microtime(true);
        foreach ($this->db->all('SELECT * FROM wyn_sites') as $site) {
            $rolled += $this->catchUp($site, $now, $budgetSeconds === null ? null : max(0.1, $budgetSeconds - (microtime(true) - $start)));
        }
        $pruned = $this->prune($now);
        $this->salts->rotate($now);
        $this->db->query('DELETE FROM wyn_login_attempts WHERE attempted_at < ?', [$now - 86400]);
        $this->settings->set('last_run_at', (string) $now);

        return ['rolled' => $rolled] + $pruned;
    }

    /** Days waiting for a rollup, oldest first. */
    public function pending(array $site, int $now): array
    {
        $local = (new \DateTimeImmutable('@' . ($now - self::GRACE)))->setTimezone(new \DateTimeZone($site['timezone']));

        return array_column($this->db->all(
            'SELECT DISTINCT day FROM wyn_visits WHERE site_id = ? AND day < ? AND day NOT IN (SELECT day FROM wyn_rollups WHERE site_id = ?) ORDER BY day',
            [$site['id'], $local->format('Y-m-d'), $site['id']],
        ), 'day');
    }

    /**
     * Rolls up a site's closed days. Called by the scheduled job, and by the dashboard
     * when it opens, so stats are complete even where no cron job runs.
     */
    public function catchUp(array $site, int $now, ?float $budgetSeconds = null): int
    {
        $start = microtime(true);
        $count = 0;
        foreach ($this->pending($site, $now) as $day) {
            $this->day($site, $day, $now);
            $count++;
            if ($budgetSeconds !== null && microtime(true) - $start > $budgetSeconds) {
                break; // the rest is done on the next run
            }
        }

        return $count;
    }

    /** (Re)builds one day's rollup from raw data. */
    public function day(array $site, string $day, int $now): void
    {
        $agg = $this->stats->raw($site, $day, $day, [], Stats::ROLLUP_DIMS);

        $this->db->immediate(function (Database $db) use ($site, $day, $agg, $now) {
            $db->query('DELETE FROM wyn_daily WHERE site_id = ? AND day = ?', [$site['id'], $day]);
            $insert = $db->pdo->prepare('INSERT INTO wyn_daily (site_id, day, dim, value, visitors, visits, pageviews, bounces, duration, events) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($agg as $dim => $values) {
                foreach (self::cap($values) as $value => $m) {
                    $insert->execute([$site['id'], $day, $dim, (string) $value, $m['visitors'], $m['visits'], $m['pageviews'], $m['bounces'], $m['duration'], $m['events']]);
                }
            }
            $db->query('INSERT INTO wyn_rollups (site_id, day, rolled_at) VALUES (?, ?, ?) ON CONFLICT(site_id, day) DO UPDATE SET rolled_at = excluded.rolled_at', [$site['id'], $day, $now]);
        });
    }

    /**
     * Rebuilds rolled-up days that still have raw data, e.g. after a goal is added,
     * so the goal shows conversions for the whole retention period.
     */
    public function rebuild(array $site, int $now): int
    {
        $days = array_column($this->db->all(
            'SELECT r.day FROM wyn_rollups r WHERE r.site_id = ? AND EXISTS (SELECT 1 FROM wyn_visits v WHERE v.site_id = r.site_id AND v.day = r.day) ORDER BY r.day',
            [$site['id']],
        ), 'day');
        foreach ($days as $day) {
            $this->day($site, $day, $now);
        }

        return count($days);
    }

    /** Deletes raw data older than the retention period, for days that are rolled up. */
    public function prune(int $now): array
    {
        $days = $this->settings->retentionDays();
        $visits = $events = 0;
        foreach ($this->db->all('SELECT id, timezone FROM wyn_sites') as $site) {
            $cutoff = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone($site['timezone']))->modify("-{$days} days")->format('Y-m-d');
            $rolled = 'day IN (SELECT day FROM wyn_rollups WHERE site_id = ? AND day < ?)';
            $this->db->immediate(function (Database $db) use ($site, $cutoff, $rolled, &$visits, &$events) {
                $events += $db->query("DELETE FROM wyn_events WHERE site_id = ? AND day < ? AND {$rolled}", [$site['id'], $cutoff, $site['id'], $cutoff])->rowCount();
                $visits += $db->query("DELETE FROM wyn_visits WHERE site_id = ? AND day < ? AND {$rolled}", [$site['id'], $cutoff, $site['id'], $cutoff])->rowCount();
            });
        }

        return ['pruned_visits' => $visits, 'pruned_events' => $events];
    }

    /** Keeps the biggest values; the rest of the long tail is summed into "(other)". */
    private static function cap(array $values): array
    {
        if (count($values) <= self::MAX_VALUES) {
            return $values;
        }
        uasort($values, fn ($a, $b) => [$b['visitors'], $b['pageviews'], $b['events']] <=> [$a['visitors'], $a['pageviews'], $a['events']]);
        $keep = array_slice($values, 0, self::MAX_VALUES - 1, true);
        $other = ['visitors' => 0, 'visits' => 0, 'pageviews' => 0, 'bounces' => 0, 'duration' => 0, 'events' => 0];
        foreach (array_slice($values, self::MAX_VALUES - 1, null, true) as $m) {
            foreach ($other as $k => $v) {
                $other[$k] = $v + $m[$k];
            }
        }
        $keep['(other)'] = $other;

        return $keep;
    }
}
