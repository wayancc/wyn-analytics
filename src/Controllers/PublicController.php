<?php

declare(strict_types=1);

namespace WynAnalytics\Controllers;

use WynAnalytics\App;
use WynAnalytics\Http\Request;
use WynAnalytics\Http\Response;
use WynAnalytics\NotFound;
use WynAnalytics\Period;
use WynAnalytics\Report;
use WynAnalytics\Stats;

final class PublicController
{
    public function __construct(private readonly App $app)
    {
    }

    /** The tracking script, compacted and pointed at this install's event path. */
    public function script(Request $r): Response
    {
        $js = self::compact((string) file_get_contents(WYN_ROOT . '/public/assets/wyn-analytics.js'));
        $endpoint = $this->app->url(ltrim((string) $this->app->config->get('event_path'), '/'));
        $js = str_replace("'/wyn/e'", json_encode($endpoint, JSON_UNESCAPED_SLASHES), $js);

        $etag = '"' . substr(md5($js), 0, 16) . '"';
        $headers = [
            'Content-Type'  => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'ETag'          => $etag,
            'Vary'          => 'Accept-Encoding',
            'Access-Control-Allow-Origin' => '*',
        ];
        if ($r->header('if-none-match') === $etag) {
            return new Response(304, '', $headers);
        }
        if (function_exists('gzencode') && str_contains((string) $r->header('accept-encoding'), 'gzip')) {
            $js = (string) gzencode($js, 9);
            $headers['Content-Encoding'] = 'gzip';
        }

        return new Response(200, $js, $headers);
    }

    /** Drops comment lines and indentation. Keeps the license header. Safe for this file only. */
    public static function compact(string $js): string
    {
        $lines = [];
        foreach (explode("\n", $js) as $i => $line) {
            $t = trim($line);
            if ($t === '' || (str_starts_with($t, '//') && $i > 3)) {
                continue;
            }
            $lines[] = $i <= 3 ? rtrim($line) : $t;
        }

        return implode("\n", $lines) . "\n";
    }

    // ------------------------------------------------------------------ Public stats page

    public function stats(Request $r, string $domain): Response
    {
        $site = $this->app->sites->findByDomain($domain);
        if ($site === null || !$site['public']) {
            throw new NotFound();
        }

        $unlockKey = 'stats_unlocked_' . $site['id'];
        if ($site['public_password'] !== '' && !$this->app->session->get($unlockKey)) {
            $error = null;
            if ($r->method === 'POST') {
                $who = 'stats:' . $site['id'] . ':' . $this->app->settings->hash($r->ip);
                if (!$this->app->session->validCsrf($r->post['_token'] ?? null)) {
                    $error = 'The page expired. Try again.';
                } elseif ($this->app->auth->isThrottled($who)) {
                    $error = 'Too many attempts. Wait 15 minutes and try again.';
                } elseif (password_verify((string) ($r->post['password'] ?? ''), $site['public_password'])) {
                    $this->app->session->regenerate();
                    $this->app->session->set($unlockKey, true);

                    return Response::redirect($this->app->url('stats/' . rawurlencode($site['domain'])));
                } else {
                    $this->app->db->insert('wyn_login_attempts', ['who' => $who, 'attempted_at' => $this->app->now()]);
                    $error = 'That password isn’t right.';
                }
            }

            return $this->page('public/locked', ['site' => $site, 'error' => $error], $site['name'], 200);
        }
        if ($r->method === 'POST') {
            return Response::redirect($this->app->url('stats/' . rawurlencode($site['domain'])));
        }

        $this->app->rollup->catchUp($site, $this->app->now(), 2.0);
        $key = array_key_exists($r->input('range'), Period::RANGES) ? $r->input('range') : '30d';
        $period = Period::make($key, $site['timezone'], $this->app->now());
        $report = Report::build($this->app, $site, $period, [], ['page', 'source', 'country', 'device', 'browser'], 8);

        return $this->page('public/stats', ['site' => $site, 'report' => $report, 'metric' => 'visitors'], $site['name'] . ' stats');
    }

    // ------------------------------------------------------------------ API

    public function api(Request $r): Response
    {
        $auth = (string) $r->header('authorization');
        $token = str_starts_with($auth, 'Bearer ') ? trim(substr($auth, 7)) : $r->input('token');
        $site = $token !== '' ? $this->app->sites->findByToken($token) : null;
        if ($site === null) {
            return self::json(['error' => 'Send a valid read token: Authorization: Bearer wyn_…'], 401);
        }
        if ($r->input('site') !== '' && $r->input('site') !== $site['domain']) {
            return self::json(['error' => 'This token belongs to a different site.'], 403);
        }

        $this->app->rollup->catchUp($site, $this->app->now(), 5.0);
        $period = Period::make($r->input('range', '30d'), $site['timezone'], $this->app->now(), $r->input('from'), $r->input('to'));
        $limit = max(1, min(100, (int) $r->input('limit', '10')));
        $report = Report::build($this->app, $site, $period, Stats::cleanFilters($r->query), array_keys(Report::DIMS), $limit);

        return self::json($report->toArray());
    }

    private static function json(array $data, int $status = 200): Response
    {
        $res = Response::json($data, $status);
        $res->headers['Access-Control-Allow-Origin'] = '*';

        return $res;
    }

    // ------------------------------------------------------------------ Web cron

    public function cron(Request $r, string $token): Response
    {
        if (!hash_equals($this->app->settings->secret('cron_token'), $token)) {
            return Response::text("Invalid token\n", 403);
        }
        ignore_user_abort(true);
        set_time_limit(120);
        $result = $this->app->rollup->run($this->app->now(), 50.0);

        return Response::text("Rolled up {$result['rolled']} days, removed {$result['pruned_visits']} old visits\n");
    }

    public function notFound(): Response
    {
        return $this->page('public/message', [
            'heading' => 'Not found',
            'text'    => 'There’s nothing at this address.',
        ], 'Not found', 404);
    }

    private function page(string $template, array $vars, string $title, int $status = 200): Response
    {
        $html = $this->app->view()->render($template, $vars + ['title' => $title]);

        return new Response($status, $html, [
            'Content-Type'    => 'text/html; charset=utf-8',
            'Cache-Control'   => 'no-store',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }
}
