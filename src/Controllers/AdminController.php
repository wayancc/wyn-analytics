<?php

declare(strict_types=1);

namespace WynAnalytics\Controllers;

use WynAnalytics\App;
use WynAnalytics\Http\Request;
use WynAnalytics\Http\Response;
use WynAnalytics\NotFound;
use WynAnalytics\Period;
use WynAnalytics\Report;
use WynAnalytics\Settings;
use WynAnalytics\Stats;
use WynAnalytics\Urls;

final class AdminController
{
    /** Tabs inside each dashboard panel: panel => [query key, tabs]. */
    public const PANELS = [
        'pages'   => ['Pages', ['page' => 'Top', 'entry' => 'Entry', 'exit' => 'Exit']],
        'sources' => ['Sources', ['source' => 'Sources', 'referrer' => 'Referrers', 'utm_source' => 'UTM source', 'utm_medium' => 'Medium', 'utm_campaign' => 'Campaign']],
        'places'  => ['Locations', ['country' => 'Countries']],
        'devices' => ['Devices', ['device' => 'Devices', 'browser' => 'Browsers', 'os' => 'OS', 'screen' => 'Screens']],
    ];

    public function __construct(private readonly App $app)
    {
    }

    // ---------------------------------------------------------------- Auth

    public function setup(Request $r): Response
    {
        $errors = [];
        if ($r->method === 'POST' && !$this->app->session->validCsrf($r->post['_token'] ?? null)) {
            $errors['email'] = 'Your session expired. Try again.';
        } elseif ($r->method === 'POST') {
            $email = $r->input('email');
            $password = (string) ($r->post['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Enter a valid email address.';
            }
            if (strlen($password) < 10) {
                $errors['password'] = 'Use at least 10 characters.';
            }
            if ($errors === []) {
                $id = $this->app->auth->createUser($email, $password);
                $this->app->settings->set('site_name', mb_substr($r->input('site_name'), 0, 60) ?: 'Wyn Analytics');
                $this->app->auth->login($id);
                $this->app->session->flash('Account created. Add the first website you want to measure.');

                return Response::redirect($this->app->url('admin/sites/new'));
            }
        }

        return $this->page('admin/setup', ['errors' => $errors, 'old' => $r->post], 'Set up', bare: true);
    }

    public function login(Request $r): Response
    {
        $error = null;
        if ($r->method === 'POST') {
            $who = 'login:' . $this->app->settings->hash($r->ip);
            if ($this->app->auth->isThrottled($who)) {
                $error = 'Too many attempts. Wait 15 minutes and try again.';
            } elseif ($this->app->auth->attempt($r->input('email'), (string) ($r->post['password'] ?? ''), $who)) {
                return Response::redirect($this->app->url('admin'));
            } else {
                $error = 'That email and password don’t match.';
            }
        }

        return $this->page('admin/login', ['error' => $error, 'email' => $r->input('email')], 'Sign in', bare: true);
    }

    public function logout(Request $r): Response
    {
        $this->app->auth->logout();

        return Response::redirect($this->app->url('admin/login'));
    }

    // ---------------------------------------------------------------- Sites

    public function sites(Request $r): Response
    {
        $now = $this->app->now();
        $rows = [];
        foreach ($this->app->sites->all() as $site) {
            $this->app->rollup->catchUp($site, $now, 1.0);
            $period = Period::make('7d', $site['timezone'], $now);
            $series = $this->app->stats->series($site, $period);
            $week = Stats::summary($this->app->stats->totals($site, $period->from, $period->to));
            $before = Stats::summary($this->app->stats->totals($site, $period->prevFrom, $period->prevTo, [], $period->prevCutoff));
            $rows[] = [
                'site'   => $site,
                'spark'  => array_map(fn ($m) => $m['visitors'], $series),
                'week'   => $week,
                'change' => \WynAnalytics\Format::change($week['visitors'], $before['visitors']),
                'now'    => $this->app->stats->now($site, $now),
            ];
        }

        return $this->page('admin/sites', ['rows' => $rows], 'Sites', 'sites');
    }

    public function siteForm(Request $r, ?string $id = null): Response
    {
        $site = $id !== null ? $this->site($id) : $this->app->sites->defaults();

        return $this->page('admin/site-settings', $this->settingsVars($site, []), $id ? 'Settings' : 'Add a site', $id ? 'settings-tab' : 'sites');
    }

    public function siteSave(Request $r, ?string $id = null): Response
    {
        $existing = $id !== null ? $this->site($id) : null;
        [$savedId, $errors, $data] = $this->app->sites->save($r->post, $existing ? (int) $existing['id'] : null);

        if ($errors !== []) {
            $site = $data + ($existing ?? $this->app->sites->defaults());

            return $this->page('admin/site-settings', $this->settingsVars($site, $errors), $existing ? 'Settings' : 'Add a site', $existing ? 'settings-tab' : 'sites', 422);
        }
        if ($existing) {
            if ($existing['timezone'] !== $data['timezone']) {
                $this->app->session->flash('Time zone changed. New visits use it from now on; past days keep the dates they were counted on.', 'warn');
            }
            $this->app->session->flash('Settings saved.');

            return Response::redirect($this->app->url('admin/sites/' . $savedId . '/settings'));
        }
        $this->app->session->flash('Site added. Paste the script below into your website.');

        return Response::redirect($this->app->url('admin/sites/' . $savedId . '/setup'));
    }

    public function setupSite(Request $r, string $id): Response
    {
        $site = $this->site($id);

        return $this->page('admin/site-setup', [
            'site'    => $site,
            'snippet' => $this->app->snippet($site),
            'script'  => Urls::absolute($this->app->config, ltrim((string) $this->app->config->get('script_path'), '/')),
            'ignore'  => 'https://' . $site['domain'] . '/#wyn-ignore',
            'event'   => Urls::absolute($this->app->config, ltrim((string) $this->app->config->get('event_path'), '/')),
        ], 'Add to your site', 'setup-tab');
    }

    /** JSON for the live counter and the "waiting for the first visit" check. */
    public function live(Request $r, string $id): Response
    {
        $site = $this->site($id);

        return Response::json([
            'now'         => $this->app->stats->now($site, $this->app->now()),
            'first_event' => $site['first_event_at'] !== null,
        ]);
    }

    public function dashboard(Request $r, string $id): Response
    {
        $site = $this->site($id);
        $now = $this->app->now();
        $this->app->rollup->catchUp($site, $now, 5.0);

        $period = Period::make($r->input('range', '30d'), $site['timezone'], $now, $r->input('from'), $r->input('to'));
        $filters = Stats::cleanFilters($r->query);
        $metric = array_key_exists($r->input('metric'), Report::METRICS) ? $r->input('metric') : 'visitors';
        $tabs = [];
        foreach (self::PANELS as $panel => [, $options]) {
            $tabs[$panel] = array_key_exists($r->input($panel), $options) ? $r->input($panel) : array_key_first($options);
        }
        $report = Report::build($this->app, $site, $period, $filters, [...array_values($tabs), 'event'], 10);

        return $this->page('admin/dashboard', [
            'site'    => $site,
            'report'  => $report,
            'metric'  => $metric,
            'tabs'    => $tabs,
            'panels'  => self::PANELS,
            'query'   => $period->query() + self::filterQuery($filters) + ['metric' => $metric] + $tabs,
            'geo'     => $this->app->geo->available() ? $this->app->geo->label() : '',
            'retention' => $this->app->settings->retentionDays(),
        ], $site['name'], 'dashboard-tab');
    }

    public function export(Request $r, string $id): Response
    {
        $site = $this->site($id);
        $period = Period::make($r->input('range', '30d'), $site['timezone'], $this->app->now(), $r->input('from'), $r->input('to'));
        $filters = Stats::cleanFilters($r->query);
        $table = $r->input('table', 'visitors');

        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8 correctly
        if ($table === 'visitors') {
            $periodDays = $period->interval === 'hour' ? $period : $period->daily();
            fputcsv($out, ['Date', 'Unique visitors', 'Visits', 'Pageviews', 'Bounce rate (%)', 'Avg visit duration (s)'], escape: '');
            foreach ($this->app->stats->series($site, $periodDays, $filters) as $key => $m) {
                $label = $period->interval === 'hour' ? $period->from . ' ' . $periodDays->buckets()[$key] : $key;
                fputcsv($out, [$label, $m['visitors'], $m['visits'], $m['pageviews'],
                    $m['visits'] ? round($m['bounces'] / $m['visits'] * 100, 1) : '', $m['visits'] ? round($m['duration'] / $m['visits']) : ''], escape: '');
            }
        } elseif ($table === 'goals') {
            fputcsv($out, ['Goal', 'Unique conversions', 'Total completions', 'Conversion rate (%)'], escape: '');
            foreach ($this->app->stats->goals($site, $period->from, $period->to, $filters) as $g) {
                fputcsv($out, [self::csvSafe($g['label']), $g['visitors'], $g['completions'], round($g['rate'], 2)], escape: '');
            }
        } elseif (array_key_exists($table, Report::DIMS)) {
            $label = Report::DIMS[$table];
            fputcsv($out, [$label, 'Unique visitors', $table === 'event' ? 'Events' : ($table === 'page' ? 'Pageviews' : 'Visits')], escape: '');
            foreach ($this->app->stats->breakdown($site, $period->from, $period->to, $table, $filters, 5000) as $value => $m) {
                fputcsv($out, [self::csvSafe((string) $value), $m['visitors'], $table === 'event' ? $m['events'] : ($table === 'page' ? $m['pageviews'] : $m['visits'])], escape: '');
            }
        } else {
            throw new NotFound();
        }
        rewind($out);
        $name = preg_replace('/[^a-z0-9]+/', '-', $site['domain']) . '-' . $table . '-' . $period->from . '-to-' . $period->to . '.csv';

        return new Response(200, (string) stream_get_contents($out), [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    public function siteAction(Request $r, string $id, string $action): Response
    {
        $site = $this->site($id);
        $sid = (int) $site['id'];
        $back = fn (string $anchor) => Response::redirect($this->app->url("admin/sites/{$sid}/settings#{$anchor}"));

        switch ($action) {
            case 'delete':
                if (strtolower($r->input('confirm_domain')) !== $site['domain']) {
                    $this->app->session->flash('Type the domain exactly to delete the site.', 'error');

                    return $back('danger');
                }
                $this->app->sites->delete($sid);
                $this->app->session->flash("{$site['domain']} and all its stats were deleted.");

                return Response::redirect($this->app->url('admin'));

            case 'goal-add':
                [$goalId, $error] = $this->app->sites->addGoal($sid, $r->input('kind'), $r->input('value'), $this->app->now());
                if ($error !== null) {
                    $this->app->session->flash($error, 'error');
                } else {
                    // Count the new goal across the raw data we still have, not only from today.
                    $this->app->rollup->rebuild($site, $this->app->now());
                    $this->app->session->flash('Goal added. Conversions are counted for the last ' . $this->app->settings->retentionDays() . ' days too.');
                }

                return $back('goals');

            case 'goal-delete':
                $this->app->db->query('DELETE FROM wyn_goals WHERE id = ? AND site_id = ?', [(int) $r->input('goal'), $sid]);
                $this->app->session->flash('Goal removed.');

                return $back('goals');

            case 'exclude-ip':
                $ip = $r->input('mine') === '1' ? $r->ip : $r->input('ip');
                $error = $this->app->sites->excludeIp($site, $ip, $r->input('mine') === '1' ? 'My connection' : $r->input('label'));
                $this->app->session->flash($error ?? 'Visits from that address are no longer counted.', $error ? 'error' : 'ok');

                return $back('exclude');

            case 'unexclude':
                $this->app->sites->removeExcluded($site, $r->input('hash'));
                $this->app->session->flash('Address removed from the list.');

                return $back('exclude');

            case 'token':
                $token = $this->app->sites->newToken($sid);
                $this->app->session->set('new_token', $token);
                $this->app->session->flash('New read token created. Copy it now: it’s shown only once.');

                return $back('api');

            case 'token-revoke':
                $this->app->sites->revokeToken($sid);
                $this->app->session->flash('Read token revoked. Apps using it can no longer read stats.');

                return $back('api');
        }

        throw new NotFound();
    }

    // ---------------------------------------------------------------- Settings

    public function settings(Request $r): Response
    {
        $values = [];
        foreach (array_keys(Settings::DEFAULTS) as $key) {
            $values[$key] = $this->app->settings->get($key);
        }

        return $this->page('admin/settings', [
            's'       => $values,
            'cronUrl' => Urls::absolute($this->app->config, 'cron/' . $this->app->settings->secret('cron_token')),
            'lastRun' => (int) $values['last_run_at'],
            'geo'     => $this->app->geo->available() ? $this->app->geo->label() : '',
        ], 'Settings', 'settings');
    }

    public function settingsSave(Request $r): Response
    {
        if ($r->input('regenerate_cron') === '1') {
            $this->app->settings->set('cron_token', bin2hex(random_bytes(16)));
            $this->app->session->flash('New web-cron URL created. Update your cron service with it.', 'warn');

            return Response::redirect($this->app->url('admin/settings#rollups'));
        }
        $this->app->settings->set('site_name', mb_substr($r->input('site_name'), 0, 60) ?: 'Wyn Analytics');
        $this->app->settings->set('theme', $r->input('theme') === 'neutral' ? 'neutral' : 'wayan');
        $days = (int) $r->input('retention_days');
        $this->app->settings->set('retention_days', (string) (in_array($days, [7, 30, 90, 180, 365], true) ? $days : 30));
        $this->app->session->flash('Settings saved.');

        return Response::redirect($this->app->url('admin/settings'));
    }

    public function passwordSave(Request $r): Response
    {
        $user = $this->app->auth->user();
        $new = (string) ($r->post['new'] ?? '');
        if (!$this->app->auth->verifyPassword((int) $user['id'], (string) ($r->post['current'] ?? ''))) {
            $this->app->session->flash('Your current password is incorrect.', 'error');
        } elseif (strlen($new) < 10) {
            $this->app->session->flash('Use at least 10 characters for the new password.', 'error');
        } else {
            $this->app->auth->changePassword((int) $user['id'], $new);
            $this->app->session->flash('Password changed.');
        }

        return Response::redirect($this->app->url('admin/settings#account'));
    }

    // ---------------------------------------------------------------- Helpers

    private function settingsVars(array $site, array $errors): array
    {
        $token = $this->app->session->get('new_token');
        $this->app->session->forget('new_token');

        return [
            'site'      => $site,
            'errors'    => $errors,
            'goals'     => $site['id'] ? $this->app->sites->goals((int) $site['id']) : [],
            'excluded'  => $site['id'] ? $this->app->sites->excluded($site) : [],
            'newToken'  => $token,
            'timezones' => \DateTimeZone::listIdentifiers(),
            'apiUrl'    => Urls::absolute($this->app->config, 'api/stats.json'),
            'publicUrl' => Urls::absolute($this->app->config, 'stats/' . rawurlencode((string) $site['domain'])),
        ];
    }

    private function page(string $template, array $vars, string $title, string $nav = '', int $status = 200, bool $bare = false): Response
    {
        $html = $this->app->view()->render($template, $vars + ['title' => $title, 'nav' => $nav, 'bare' => $bare]);

        return new Response($status, $html, [
            'Content-Type'    => 'text/html; charset=utf-8',
            'Cache-Control'   => 'no-store',
            'X-Frame-Options' => 'DENY',
        ]);
    }

    private function site(string $id): array
    {
        return $this->app->sites->find((int) $id) ?? throw new NotFound();
    }

    public static function filterQuery(array $filters): array
    {
        $out = [];
        foreach ($filters as $dim => $value) {
            $out['f_' . $dim] = $value;
        }

        return $out;
    }

    /** Stop spreadsheet apps from treating a value as a formula. */
    private static function csvSafe(string $value): string
    {
        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'" . $value : $value;
    }
}
