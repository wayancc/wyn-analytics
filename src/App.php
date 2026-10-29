<?php

declare(strict_types=1);

namespace WynAnalytics;

use WynAnalytics\Controllers\AdminController;
use WynAnalytics\Controllers\PublicController;
use WynAnalytics\Http\Request;
use WynAnalytics\Http\Response;
use WynAnalytics\Http\Router;
use WynAnalytics\Http\Session;

/**
 * Wires everything together. One instance per request (web) or per command (CLI).
 */
final class App
{
    public readonly Database $db;
    public readonly Settings $settings;
    public readonly Session $session;
    public readonly Auth $auth;
    public readonly Sites $sites;
    public readonly Salts $salts;
    public readonly Geo $geo;
    public readonly Tracker $tracker;
    public readonly Stats $stats;
    public readonly Rollup $rollup;

    /** Tests set this to move time. */
    public ?int $fixedNow = null;

    public function __construct(public readonly Config $config)
    {
        date_default_timezone_set((string) $config->get('timezone') ?: 'UTC');

        $clock = fn (): int => $this->now();
        $this->db = new Database($config->databasePath());
        $this->settings = new Settings($this->db);
        $this->session = new Session();
        $this->auth = new Auth($this->db, $this->session);
        $this->sites = new Sites($this->db, $this->settings);
        $this->salts = new Salts($this->db, $clock);
        $this->geo = new Geo($config->geoPath());
        $this->tracker = new Tracker($this->db, $this->sites, $this->salts, $this->geo, (bool) $config->get('trust_proxy'));
        $this->stats = new Stats($this->db, $this->sites);
        $this->rollup = new Rollup($this->db, $this->stats, $this->salts, $this->settings);
    }

    public static function boot(string $root, array $overrides = []): self
    {
        return new self(Config::load($root, $overrides));
    }

    public function now(): int
    {
        return $this->fixedNow ?? time();
    }

    public function handle(Request $request): Response
    {
        // Any request drops salts whose date has ended, not only a new day's first visit
        // or the scheduled job, so a quiet site's last salt doesn't linger for days.
        $this->salts->rotate($this->now());

        $public = new PublicController($this);
        $eventPath = (string) $this->config->get('event_path');

        // The busiest route by far: skip routing and sessions entirely.
        if ($request->path === $eventPath) {
            return $this->tracker->handle($request, $this->now());
        }
        if ($request->path === $this->config->get('script_path')) {
            return $public->script($request);
        }

        $isAdmin = str_starts_with($request->path, '/admin');
        if ($isAdmin || str_starts_with($request->path, '/stats/')) {
            $this->session->start($request->secure, $this->config->baseUrl());
        }

        $router = new Router();
        $admin = new AdminController($this);

        $router->add('GET', '/', fn () => Response::redirect($this->url('admin')));
        $router->add('GET|POST', '/stats/{domain}', $public->stats(...));
        $router->add('GET', '/api/stats.json', $public->api(...));
        $router->add('GET|POST', '/cron/{token}', $public->cron(...));

        $router->add('GET|POST', '/admin/setup', $admin->setup(...));
        $router->add('GET|POST', '/admin/login', $admin->login(...));
        $router->add('POST', '/admin/logout', $admin->logout(...));
        $router->add('GET', '/admin', $admin->sites(...));
        $router->add('GET', '/admin/sites/new', $admin->siteForm(...));
        $router->add('POST', '/admin/sites', $admin->siteSave(...));
        $router->add('GET', '/admin/sites/{id}', $admin->dashboard(...));
        $router->add('GET', '/admin/sites/{id}/now.json', $admin->live(...));
        $router->add('GET', '/admin/sites/{id}/export.csv', $admin->export(...));
        $router->add('GET', '/admin/sites/{id}/setup', $admin->setupSite(...));
        $router->add('GET', '/admin/sites/{id}/settings', $admin->siteForm(...));
        $router->add('POST', '/admin/sites/{id}/settings', $admin->siteSave(...));
        $router->add('POST', '/admin/sites/{id}/{action}', $admin->siteAction(...));
        $router->add('GET', '/admin/settings', $admin->settings(...));
        $router->add('POST', '/admin/settings', $admin->settingsSave(...));
        $router->add('POST', '/admin/settings/password', $admin->passwordSave(...));

        if ($isAdmin && ($guard = $this->guard($request)) !== null) {
            return $guard;
        }

        try {
            return $router->dispatch($request) ?? $public->notFound();
        } catch (NotFound) {
            return $public->notFound();
        }
    }

    /** Admin pages need a signed-in user (or first-run setup) and a CSRF token on every POST. */
    private function guard(Request $request): ?Response
    {
        if (!$this->auth->hasUsers()) {
            return $request->path === '/admin/setup' ? null : Response::redirect($this->url('admin/setup'));
        }
        if ($request->path === '/admin/setup') {
            return Response::redirect($this->url('admin'));
        }
        if ($request->path !== '/admin/login' && $this->auth->user() === null) {
            return Response::redirect($this->url('admin/login'));
        }
        if ($request->method === 'POST' && !$this->session->validCsrf($request->post['_token'] ?? null)) {
            return Response::html('<p>This form expired. Go back, reload the page and try again.</p>', 419);
        }

        return null;
    }

    public function view(): View
    {
        return new View(WYN_ROOT . '/templates', $this->config, $this->settings, $this->session, $this->auth->user());
    }

    public function url(string $path = ''): string
    {
        return $this->config->baseUrl() . ltrim($path, '/');
    }

    /** The script tag for a site, as shown on its setup page. */
    public function snippet(array $site): string
    {
        return '<script defer data-wyn-site="' . htmlspecialchars($site['domain']) . '" src="'
            . htmlspecialchars(Urls::absolute($this->config, ltrim((string) $this->config->get('script_path'), '/'))) . '"></script>';
    }
}
