<?php

declare(strict_types=1);

namespace WynAnalytics;

use WynAnalytics\Http\Request;
use WynAnalytics\Http\Response;

/**
 * Receives events from the tracking script at /wyn/e.
 *
 * Every request gets the same quick 202 answer, so a page never waits on analytics and
 * nobody can probe which sites exist. The X-Wyn-Result header says what happened
 * ("counted" or "ignored: reason"), which helps when setting up a site.
 */
final class Tracker
{
    public const VISIT_GAP = 1800;          // 30 minutes without activity ends a visit
    private const MAX_PER_VISIT = 2000;     // stop counting a single visit that sends thousands of events
    private const MAX_ENGAGED_MS = 1_800_000;

    public function __construct(
        private readonly Database $db,
        private readonly Sites $sites,
        private readonly Salts $salts,
        private readonly Geo $geo,
        private readonly bool $trustProxy,
    ) {
    }

    public function handle(Request $request, int $now): Response
    {
        if ($request->method === 'OPTIONS') {
            return self::reply(204, '');
        }
        if ($request->method !== 'POST') {
            return self::reply(405, 'ignored: send events with POST');
        }

        return self::reply(202, $this->track($request, $now));
    }

    /** @return string "counted" or "ignored: reason" */
    public function track(Request $request, int $now): string
    {
        $data = json_decode($request->body, true);
        if (!is_array($data)) {
            return 'ignored: body is not JSON';
        }

        $ua = (string) $request->header('user-agent');
        if (UserAgent::isBot($ua)) {
            return 'ignored: bot';
        }

        $site = $this->sites->findByDomain((string) ($data['d'] ?? ''));
        if ($site === null) {
            return 'ignored: unknown site';
        }

        $url = parse_url((string) ($data['u'] ?? ''));
        if (!is_array($url) || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || !Sites::hostMatches($site, (string) ($url['host'] ?? ''))) {
            return 'ignored: page is not on ' . $site['domain'];
        }

        if ($site['respect_gpc'] && ($request->header('sec-gpc') === '1' || !empty($data['g']))) {
            return 'ignored: Global Privacy Control';
        }
        if ($this->sites->isExcluded($site, $request->ip)) {
            return 'ignored: excluded IP';
        }

        $name = Referrers::clean((string) ($data['n'] ?? 'pageview'));
        $type = match ($name) {
            'pageview', '' => 'pageview',
            'engagement'   => 'engagement',
            default        => 'event',
        };
        $name = mb_substr($name, 0, 60);
        $path = self::path((string) ($url['path'] ?? '/'));
        parse_str((string) ($url['query'] ?? ''), $query);

        $tz = new \DateTimeZone($site['timezone']);
        $day = (new \DateTimeImmutable('@' . $now))->setTimezone($tz)->format('Y-m-d');
        $visitor = $this->salts->visitorId((int) $site['id'], $request->ip, $ua, $day);

        $result = $this->db->immediate(function (Database $db) use ($site, $visitor, $now, $day, $type, $name, $path, $data, $query, $ua, $request) {
            $visit = $db->one(
                'SELECT id, pageviews, events FROM wyn_visits WHERE site_id = ? AND visitor = ? AND last_at >= ? ORDER BY last_at DESC LIMIT 1',
                [$site['id'], $visitor, $now - self::VISIT_GAP],
            );

            if ($type === 'engagement') {
                if ($visit === null) {
                    return 'ignored: no visit to add time to';
                }
                $ms = max(0, min(self::MAX_ENGAGED_MS, (int) ($data['e'] ?? 0)));
                $db->query('UPDATE wyn_visits SET engaged_ms = engaged_ms + ?, last_at = MAX(last_at, ?) WHERE id = ?', [$ms, $now, $visit['id']]);
                $db->query(
                    "UPDATE wyn_events SET engaged_ms = engaged_ms + ? WHERE id = (SELECT id FROM wyn_events WHERE visit_id = ? AND type = 'pageview' AND path = ? ORDER BY id DESC LIMIT 1)",
                    [$ms, $visit['id'], $path],
                );

                return 'counted';
            }

            if ($visit !== null && $visit['pageviews'] + $visit['events'] >= self::MAX_PER_VISIT) {
                return 'ignored: too many events in one visit';
            }

            if ($visit === null) {
                $visitId = $db->insert('wyn_visits', [
                    'site_id'    => $site['id'],
                    'visitor'    => $visitor,
                    'day'        => $day,
                    'started_at' => $now,
                    'last_at'    => $now,
                    'entry_path' => $path,
                    'exit_path'  => $path,
                    'pageviews'  => $type === 'pageview' ? 1 : 0,
                    'events'     => $type === 'event' ? 1 : 0,
                ] + $this->attribution($site, (string) ($data['r'] ?? ''), $query, $ua, (int) ($data['w'] ?? 0), $request));
                $visitDay = $day;
            } else {
                $visitId = (int) $visit['id'];
                $visitDay = (string) $db->value('SELECT day FROM wyn_visits WHERE id = ?', [$visitId]);
                $type === 'pageview'
                    ? $db->query('UPDATE wyn_visits SET pageviews = pageviews + 1, exit_path = ?, last_at = ? WHERE id = ?', [$path, $now, $visitId])
                    : $db->query('UPDATE wyn_visits SET events = events + 1, last_at = ? WHERE id = ?', [$now, $visitId]);
            }

            $db->insert('wyn_events', [
                'site_id'  => $site['id'],
                'visit_id' => $visitId,
                'day'      => $visitDay,   // events belong to the day their visit started
                'ts'       => $now,
                'type'     => $type,
                'name'     => $type === 'event' ? $name : '',
                'path'     => $path,
                'props'    => $type === 'event' ? self::props($data['p'] ?? null) : null,
            ]);

            if ($site['first_event_at'] === null) {
                $db->query('UPDATE wyn_sites SET first_event_at = ? WHERE id = ? AND first_event_at IS NULL', [$now, $site['id']]);
            }

            return 'counted';
        });

        return $result;
    }

    /** Where a visit came from and what it was viewed on. Set once, when the visit starts. */
    private function attribution(array $site, string $referrer, array $query, string $ua, int $width, Request $request): array
    {
        $host = Referrers::host($referrer);
        if ($host !== '' && Sites::hostMatches($site, $host)) {
            $host = ''; // moving between pages of the same site is not a referral
        }
        $utm = fn (string $key) => is_string($query[$key] ?? null) ? Referrers::clean($query[$key]) : '';
        $utmSource = $utm('utm_source') ?: $utm('ref') ?: $utm('source');
        $source = $utmSource !== '' ? Referrers::sourceFromUtm($utmSource) : Referrers::source($host);
        $agent = UserAgent::parse($ua);
        $cf = $this->trustProxy ? $request->header('cf-ipcountry') : null;

        return [
            'source'       => mb_substr($source, 0, 100),
            'referrer'     => mb_substr($host, 0, 100),
            'utm_source'   => $utmSource,
            'utm_medium'   => $utm('utm_medium'),
            'utm_campaign' => $utm('utm_campaign'),
            'country'      => $this->geo->country($request->ip, $cf),
            'device'       => $agent['device'],
            'browser'      => $agent['browser'],
            'os'           => $agent['os'],
            'screen'       => UserAgent::screen($width),
        ];
    }

    /** "/Blog/Post/" → "/Blog/Post". Query strings and #hashes are never stored. */
    public static function path(string $path): string
    {
        $decoded = rawurldecode($path);
        $path = mb_check_encoding($decoded, 'UTF-8') ? $decoded : $path;
        $path = (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $path);
        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/') ?: '/';
        }

        return mb_substr($path, 0, 300);
    }

    /** Up to 5 short properties; values become strings. */
    public static function props(mixed $props): ?string
    {
        if (!is_array($props) || $props === []) {
            return null;
        }
        $clean = [];
        foreach ($props as $key => $value) {
            if (count($clean) >= 5) {
                break;
            }
            if (!is_string($key) || !(is_scalar($value))) {
                continue;
            }
            $key = mb_substr(Referrers::clean($key), 0, 40);
            $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $value = mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value), 0, 200);
            if ($key !== '') {
                $clean[$key] = $value;
            }
        }

        return $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function reply(int $status, string $result): Response
    {
        $headers = [
            'Content-Type'                 => 'text/plain; charset=utf-8',
            'Cache-Control'                => 'no-store',
            'Access-Control-Allow-Origin'  => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Access-Control-Expose-Headers' => 'X-Wyn-Result',
            'Access-Control-Max-Age'       => '86400',
        ];
        if ($result !== '') {
            $headers['X-Wyn-Result'] = $result;
        }

        return new Response($status, '', $headers);
    }
}
