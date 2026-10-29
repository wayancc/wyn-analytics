<?php

declare(strict_types=1);

namespace WynAnalytics;

/**
 * A date range in a site's time zone, the period it's compared against, and the chart interval.
 */
final class Period
{
    public const RANGES = [
        'today' => 'Today',
        '7d'    => 'Last 7 days',
        '30d'   => 'Last 30 days',
        'month' => 'This month',
        '12mo'  => 'Last 12 months',
    ];

    private function __construct(
        public readonly string $key,
        public readonly string $from,           // Y-m-d, inclusive
        public readonly string $to,             // Y-m-d, inclusive
        public readonly string $prevFrom,
        public readonly string $prevTo,
        public readonly ?int $prevCutoff,       // compare "today so far" with "yesterday until this time"
        public readonly string $label,
        public readonly string $interval,       // hour | day | month
        public readonly \DateTimeZone $tz,
    ) {
    }

    public static function make(string $key, string $timezone, int $now, string $from = '', string $to = ''): self
    {
        $p = self::build($key, $timezone, $now, $from, $to);
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone($p->tz)->format('Y-m-d');
        if ($p->prevCutoff === null && $p->to === $today) {
            // The period ends today, part-way through the day. Compare it with the previous period
            // up to the same time of day, or every comparison would start with a small drop.
            $shift = (int) (new \DateTimeImmutable($p->prevTo, self::utc()))->diff(new \DateTimeImmutable($p->to, self::utc()))->days;

            return new self($p->key, $p->from, $p->to, $p->prevFrom, $p->prevTo, $now - $shift * 86400, $p->label, $p->interval, $p->tz);
        }

        return $p;
    }

    private static function build(string $key, string $timezone, int $now, string $from, string $to): self
    {
        $tz = new \DateTimeZone($timezone);
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->setTime(0, 0);
        $d = fn (\DateTimeImmutable $x) => $x->format('Y-m-d');

        if ($key === 'custom') {
            $a = self::parseDay($from, $tz);
            $b = self::parseDay($to, $tz);
            if ($a !== null && $b !== null) {
                [$a, $b] = $a > $b ? [$b, $a] : [$a, $b];
                $b = min($b, $today);
                $a = min($a, $b);
                if ($a < $b->modify('-3 years')) {
                    $a = $b->modify('-3 years');
                }
                $days = (int) $a->diff($b)->days + 1;
                $prevTo = $a->modify('-1 day');

                return new self('custom', $d($a), $d($b), $d($prevTo->modify('-' . ($days - 1) . ' days')), $d($prevTo), null,
                    self::rangeLabel($a, $b), self::intervalFor($days), $tz);
            }
            $key = '30d';
        }

        switch ($key) {
            case 'today':
                $yesterday = $today->modify('-1 day');

                return new self('today', $d($today), $d($today), $d($yesterday), $d($yesterday), $now - 86400, 'Today', 'hour', $tz);
            case 'month':
                $first = $today->modify('first day of this month');
                $days = (int) $first->diff($today)->days + 1;
                $prevFirst = $first->modify('-1 month');
                $prevLast = min($prevFirst->modify('+' . ($days - 1) . ' days'), $first->modify('-1 day'));

                return new self('month', $d($first), $d($today), $d($prevFirst), $d($prevLast), null, $today->format('F Y'), 'day', $tz);
            case '12mo':
                $first = $today->modify('first day of this month')->modify('-11 months');

                return new self('12mo', $d($first), $d($today), $d($first->modify('-12 months')), $d($first->modify('-1 day')), null, 'Last 12 months', 'month', $tz);
            case '30d':
            case '7d':
            default:
                $n = $key === '7d' ? 7 : 30;
                $start = $today->modify('-' . ($n - 1) . ' days');

                return new self($key === '7d' ? '7d' : '30d', $d($start), $d($today), $d($start->modify("-{$n} days")), $d($start->modify('-1 day')), null,
                    self::RANGES[$key === '7d' ? '7d' : '30d'], 'day', $tz);
        }
    }

    private static function parseDay(string $value, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    private static function intervalFor(int $days): string
    {
        return $days <= 1 ? 'hour' : ($days <= 92 ? 'day' : 'month');
    }

    private static function rangeLabel(\DateTimeImmutable $a, \DateTimeImmutable $b): string
    {
        if ($a == $b) {
            return $a->format('D, M j, Y');
        }

        return $a->format($a->format('Y') === $b->format('Y') ? 'M j' : 'M j, Y') . ' – ' . $b->format('M j, Y');
    }

    /** Same range with one chart bucket per day (CSV exports). */
    public function daily(): self
    {
        return new self($this->key, $this->from, $this->to, $this->prevFrom, $this->prevTo, $this->prevCutoff, $this->label, 'day', $this->tz);
    }

    public function days(): int
    {
        return (int) (new \DateTimeImmutable($this->from, self::utc()))->diff(new \DateTimeImmutable($this->to, self::utc()))->days + 1;
    }

    /** Chart buckets: [key => label]. Hour keys are 0..n, day keys Y-m-d, month keys Y-m. */
    public function buckets(): array
    {
        $out = [];
        if ($this->interval === 'hour') {
            foreach ($this->hourBoundaries() as $i => $ts) {
                $out[$i] = (new \DateTimeImmutable('@' . $ts))->setTimezone($this->tz)->format('H:i');
            }

            return $out;
        }
        $cursor = new \DateTimeImmutable($this->from, self::utc());
        $end = new \DateTimeImmutable($this->to, self::utc());
        while ($cursor <= $end) {
            if ($this->interval === 'month') {
                $out[$cursor->format('Y-m')] = $cursor->format('M Y');
                $cursor = $cursor->modify('first day of next month');
            } else {
                $out[$cursor->format('Y-m-d')] = $cursor->format('M j');
                $cursor = $cursor->modify('+1 day');
            }
        }

        return $out;
    }

    /**
     * Start timestamp of each local hour of the single day in this period. A day with a
     * daylight-saving change has 23 or 25 hours, so this walks real time, not 24 fixed slots.
     *
     * @return list<int>
     */
    public function hourBoundaries(): array
    {
        $start = new \DateTimeImmutable($this->from . ' 00:00', $this->tz);
        $end = $start->modify('+1 day');
        $out = [];
        for ($ts = $start->getTimestamp(); $ts < $end->getTimestamp(); $ts += 3600) {
            $out[] = $ts;
        }

        return $out;
    }

    private static function utc(): \DateTimeZone
    {
        return new \DateTimeZone('UTC');
    }

    public function query(): array
    {
        return $this->key === 'custom' ? ['range' => 'custom', 'from' => $this->from, 'to' => $this->to] : ['range' => $this->key];
    }

    public function previousLabel(): string
    {
        return match ($this->key) {
            'today' => 'yesterday at this time',
            'month' => 'the same days last month',
            '12mo'  => 'the 12 months before',
            default => 'the previous ' . $this->days() . ' days',
        };
    }
}
