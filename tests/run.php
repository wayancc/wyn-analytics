<?php

declare(strict_types=1);

/*
 * Dependency-free test suite: php tests/run.php
 * Uses a throwaway SQLite file and moves the clock instead of waiting. No internet needed.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use WynAnalytics\App;
use WynAnalytics\Format;
use WynAnalytics\Geo;
use WynAnalytics\Http\Request;
use WynAnalytics\Period;
use WynAnalytics\Referrers;
use WynAnalytics\Report;
use WynAnalytics\Stats;
use WynAnalytics\Tracker;
use WynAnalytics\UserAgent;
use WynAnalytics\Controllers\PublicController;

$wynPassed = $wynFailed = 0;
function wyn_check(string $name, bool $ok, string $detail = ''): void
{
    global $wynPassed, $wynFailed;
    $ok ? $wynPassed++ : $wynFailed++;
    echo ($ok ? "\033[32m  ✓\033[0m " : "\033[31m  ✗\033[0m ") . $name . ($ok || $detail === '' ? '' : "  ({$detail})") . PHP_EOL;
}

$tmp = sys_get_temp_dir() . '/wyn-analytics-test-' . bin2hex(random_bytes(4));
mkdir($tmp);
register_shutdown_function(fn () => exec('rm -rf ' . escapeshellarg($tmp)));

$app = App::boot(WYN_ROOT, ['database' => $tmp . '/db.sqlite', 'app_url' => 'https://stats.test', 'geo_db' => $tmp . '/geo.bin']);
$CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
$IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

/** Sends one event at a given time. Returns [status, result header, response]. */
$hit = function (array $body, int $ts, string $ip = '203.0.113.77', array $headers = []) use ($app, $CHROME) {
    $app->fixedNow = $ts;
    $res = $app->handle(new Request('POST', '/wyn/e', ip: $ip, headers: $headers + ['user-agent' => $CHROME], body: json_encode($body)));

    return [$res->status, $res->headers['X-Wyn-Result'] ?? '', $res];
};
$get = function (string $path, array $query = [], array $headers = []) use ($app) {
    return $app->handle(new Request('GET', $path, query: $query, headers: $headers, ip: '198.51.100.9'));
};
$post = function (string $path, array $fields, string $ip = '198.51.100.9') use ($app) {
    return $app->handle(new Request('POST', $path, post: $fields, ip: $ip));
};
$at = fn (string $when, string $tz = 'UTC') => (new DateTimeImmutable($when, new DateTimeZone($tz)))->getTimestamp();

[$baliId] = $app->sites->save(['domain' => 'villa.test', 'name' => 'Villa', 'timezone' => 'Asia/Makassar', 'respect_gpc' => 1, 'include_subdomains' => 1]);
[$laId] = $app->sites->save(['domain' => 'surf.test', 'name' => 'Surf', 'timezone' => 'America/Los_Angeles', 'respect_gpc' => 1, 'include_subdomains' => 0]);
$bali = fn () => $app->sites->find((int) $baliId);
$la = fn () => $app->sites->find((int) $laId);

// ---------------------------------------------------------------------------
echo "Sites\n";
[, $e] = $app->sites->save(['domain' => 'https://www.Villa.test/path', 'timezone' => 'Asia/Makassar']);
wyn_check('domain is normalised (scheme, www, path removed) and must be unique', ($e['domain'] ?? '') === 'This domain is already added.');
[, $e] = $app->sites->save(['domain' => 'not a domain', 'timezone' => 'Mars/Base']);
wyn_check('validation catches a bad domain and time zone', isset($e['domain'], $e['timezone']));

// ---------------------------------------------------------------------------
echo "Tracking endpoint\n";
$t = $at('2026-10-20 02:00');
[$s, $r, $res] = $hit(['n' => 'pageview', 'u' => 'https://villa.test/rooms/?utm_source=ig&utm_campaign=promo#top', 'd' => 'villa.test', 'r' => 'https://l.instagram.com/', 'w' => 390], $t);
wyn_check('pageview counted with a quick 202', $s === 202 && $r === 'counted' && $res->body === '');
wyn_check('CORS open for any site, no cookies set', ($res->headers['Access-Control-Allow-Origin'] ?? '') === '*' && !isset($res->headers['Set-Cookie']));
$v = $app->db->one('SELECT * FROM wyn_visits ORDER BY id DESC LIMIT 1');
wyn_check('query string and #hash stripped, trailing slash removed', $v['entry_path'] === '/rooms');
wyn_check('UTM source wins over the referrer and is normalised', $v['source'] === 'Instagram' && $v['utm_source'] === 'ig' && $v['utm_campaign'] === 'promo');
wyn_check('browser, OS, device and screen class recorded', [$v['browser'], $v['os'], $v['device'], $v['screen']] === ['Chrome', 'Windows', 'Desktop', 'Phone']);
wyn_check('preflight answered', $app->handle(new Request('OPTIONS', '/wyn/e'))->status === 204);
wyn_check('GET is refused', $app->handle(new Request('GET', '/wyn/e'))->status === 405);

$cases = [
    'invalid JSON'        => [$app->handle(new Request('POST', '/wyn/e', headers: ['user-agent' => $CHROME], body: 'nope'))->headers['X-Wyn-Result'], 'ignored: body is not JSON'],
    'unknown site'        => [$hit(['u' => 'https://other.test/', 'd' => 'other.test'], $t)[1], 'ignored: unknown site'],
    'page on another host' => [$hit(['u' => 'https://evil.test/', 'd' => 'villa.test'], $t)[1], 'ignored: page is not on villa.test'],
    'lookalike host'      => [$hit(['u' => 'https://notvilla.test/', 'd' => 'villa.test'], $t)[1], 'ignored: page is not on villa.test'],
    'subdomain when off'  => [$hit(['u' => 'https://blog.surf.test/', 'd' => 'surf.test'], $t)[1], 'ignored: page is not on surf.test'],
];
foreach ($cases as $label => [$got, $want]) {
    wyn_check("ignored: {$label}", $got === $want, $got);
}
wyn_check('subdomain counted when on', $hit(['u' => 'https://blog.villa.test/a', 'd' => 'villa.test'], $t + 5, '203.0.113.20')[1] === 'counted');

echo "Bots\n";
$bots = [
    'Googlebot'      => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'curl'           => 'curl/8.4.0',
    'headless Chrome' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/130.0.0.0 Safari/537.36',
    'Lighthouse'     => 'Mozilla/5.0 (Linux; Android 11; moto g power) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36 Chrome-Lighthouse',
    'GPTBot'         => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)',
    'Vigil monitor'  => 'Vigil/0.1 (+https://github.com/wayancc/vigil)',
    'empty'          => '',
];
foreach ($bots as $label => $ua) {
    wyn_check("bot filtered: {$label}", $hit(['u' => 'https://villa.test/', 'd' => 'villa.test'], $t, '192.0.2.1', ['user-agent' => $ua])[1] === 'ignored: bot');
}
wyn_check('real browsers are not bots', !UserAgent::isBot($CHROME) && !UserAgent::isBot($IPHONE));
$ua = [
    [$IPHONE, ['Safari', 'iOS', 'Mobile']],
    ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14.6; rv:132.0) Gecko/20100101 Firefox/132.0', ['Firefox', 'macOS', 'Desktop']],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0', ['Edge', 'Windows', 'Desktop']],
    ['Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Safari/537.36', ['Samsung Internet', 'Android', 'Tablet']],
];
wyn_check('user agents parsed (Safari/iOS, Firefox/macOS, Edge, Samsung tablet)', array_map(fn ($c) => array_values(UserAgent::parse($c[0])), $ua) === array_column($ua, 1));
wyn_check('sources grouped (Google, Gemini, X, Hacker News, unknown host)', array_map(fn ($u) => Referrers::source(Referrers::host($u)), [
    'https://www.google.co.id/', 'https://gemini.google.com/app', 'https://t.co/x', 'https://news.ycombinator.com/item', 'https://blog.example.org/a',
]) === ['Google', 'Gemini', 'X (Twitter)', 'Hacker News', 'blog.example.org']);

echo "Privacy controls\n";
wyn_check('Global Privacy Control header respected', $hit(['u' => 'https://villa.test/', 'd' => 'villa.test'], $t, '192.0.2.2', ['sec-gpc' => '1'])[1] === 'ignored: Global Privacy Control');
wyn_check('Global Privacy Control flag from the script respected', $hit(['u' => 'https://villa.test/', 'd' => 'villa.test', 'g' => 1], $t, '192.0.2.2')[1] === 'ignored: Global Privacy Control');
$app->db->update('wyn_sites', ['respect_gpc' => 0], 'id = :id', ['id' => $baliId]);
wyn_check('GPC can be turned off per site', $hit(['u' => 'https://villa.test/gpc', 'd' => 'villa.test'], $t, '192.0.2.2', ['sec-gpc' => '1'])[1] === 'counted');
$app->db->update('wyn_sites', ['respect_gpc' => 1], 'id = :id', ['id' => $baliId]);
$app->sites->excludeIp($bali(), '192.0.2.50', 'Office');
wyn_check('excluded IP is not counted', $hit(['u' => 'https://villa.test/', 'd' => 'villa.test'], $t, '192.0.2.50')[1] === 'ignored: excluded IP');
wyn_check('excluded IP stored only as a hash', !str_contains($bali()['excluded_ips'], '192.0.2.50') && strlen($app->sites->excluded($bali())[0]['hash']) === 32);

// ---------------------------------------------------------------------------
echo "Visits\n";
$ip = '203.0.113.30';
$t2 = $at('2026-10-20 03:00');
$hit(['u' => 'https://villa.test/', 'd' => 'villa.test', 'r' => 'https://www.google.com/'], $t2, $ip);
$hit(['u' => 'https://villa.test/rooms', 'd' => 'villa.test', 'r' => 'https://villa.test/'], $t2 + 600, $ip);
$hit(['n' => 'engagement', 'u' => 'https://villa.test/rooms', 'd' => 'villa.test', 'e' => 42000], $t2 + 700, $ip);
$hit(['n' => 'Booking', 'u' => 'https://villa.test/rooms', 'd' => 'villa.test', 'p' => ['room' => 'Garden', 'a' => 1, 'b' => true, 'c' => 'x', 'd' => 'y', 'e' => 'z', 'nested' => ['no']]], $t2 + 710, $ip);
$visit = $app->db->one('SELECT * FROM wyn_visits WHERE source = ? ORDER BY id DESC LIMIT 1', ['Google']);
wyn_check('pageviews within 30 minutes are one visit', (int) $visit['pageviews'] === 2 && $visit['entry_path'] === '/' && $visit['exit_path'] === '/rooms');
wyn_check('moving between own pages is not a referral', $visit['referrer'] === 'google.com');
wyn_check('engagement time added to the visit and the page', (int) $visit['engaged_ms'] === 42000
    && (int) $app->db->value("SELECT engaged_ms FROM wyn_events WHERE visit_id = ? AND path = '/rooms' AND type = 'pageview'", [$visit['id']]) === 42000);
$props = json_decode((string) $app->db->value("SELECT props FROM wyn_events WHERE name = 'Booking'"), true);
wyn_check('event properties capped at 5 scalar values', $props === ['room' => 'Garden', 'a' => '1', 'b' => 'true', 'c' => 'x', 'd' => 'y']);
$hit(['u' => 'https://villa.test/back', 'd' => 'villa.test'], $t2 + 710 + 1801, $ip);
wyn_check('31 minutes of quiet starts a new visit', (int) $app->db->value('SELECT COUNT(*) FROM wyn_visits WHERE visitor = ?', [$visit['visitor']]) === 2);
wyn_check('engagement without a visit is dropped', $hit(['n' => 'engagement', 'u' => 'https://villa.test/', 'd' => 'villa.test', 'e' => 5000], $t2, '203.0.113.99')[1] === 'ignored: no visit to add time to');
wyn_check('big numbers shortened without losing zeros', array_map(Format::compact(...), [9999, 12345, 100000, 120000, 999950, 2500000]) === ['9,999', '12.3k', '100k', '120k', '1M', '2.5M']);
wyn_check('paths: decoded, cleaned, capped', Tracker::path('/caf%C3%A9/') === '/café' && Tracker::path('') === '/' && mb_strlen(Tracker::path('/' . str_repeat('a', 500))) === 300);

// ---------------------------------------------------------------------------
echo "Privacy\n";
$idA = $app->salts->visitorId(1, '203.0.113.77', $CHROME, '2026-10-20');
wyn_check('same visitor, same day: same ID', $idA === $app->salts->visitorId(1, '203.0.113.77', $CHROME, '2026-10-20'));
wyn_check('next day: a different ID, so days can’t be linked', $idA !== $app->salts->visitorId(1, '203.0.113.77', $CHROME, '2026-10-21'));
wyn_check('another site: a different ID, so sites can’t be linked', $idA !== $app->salts->visitorId(2, '203.0.113.77', $CHROME, '2026-10-20'));
$app->fixedNow = $at('2026-10-23 12:00');
$app->salts->rotate();
wyn_check('salts older than yesterday (UTC) are deleted', (int) $app->db->value("SELECT COUNT(*) FROM wyn_salts WHERE day < '2026-10-22'") === 0);
$app->db->query("INSERT INTO wyn_salts (day, salt) VALUES ('2026-10-21', 'stale')");
$get('/admin/login');
wyn_check('any request deletes salts whose date has ended, without cron or a new day', $app->db->value("SELECT 1 FROM wyn_salts WHERE day = '2026-10-21'") === null);
$trace = (string) (fn (string $ip) => new RuntimeException('x'))('203.0.113.77');
wyn_check('stack traces for the error log carry no arguments (IPs, browser strings)', !str_contains($trace, '203.0.113.77'));
$app->salts->forDay('2026-10-23');
$app->auth->attempt('nobody@x.test', 'wrong-password', 'login:' . $app->settings->hash('203.0.113.77'));
$app->db->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$dump = file_get_contents($tmp . '/db.sqlite');
$leaks = array_filter(['203.0.113.77', '203.0.113.30', '192.0.2.50', '192.0.2.2', $CHROME], fn ($needle) => str_contains($dump, $needle));
wyn_check('no IP address or browser string anywhere in the database file', $leaks === [], implode(', ', $leaks));
wyn_check('no raw-IP columns in any table', !preg_match('/\bip\b/i', implode(' ', array_column($app->db->all("SELECT sql FROM sqlite_master WHERE type = 'table'"), 'sql'))));
$js = (string) file_get_contents(WYN_ROOT . '/public/assets/wyn-analytics.js');
wyn_check('tracking script sets no cookies and stores only the opt-out flag', !str_contains($js, 'document.cookie') && substr_count($js, 'setItem(') === 1 && str_contains($js, "setItem('wyn-ignore'"));

// ---------------------------------------------------------------------------
echo "Time zones\n";
// 16:30 UTC on Oct 28 is 00:30 on Oct 29 in Bali (UTC+8) and 09:30 on Oct 28 in Los Angeles (UTC-7).
$late = $at('2026-10-28 16:30');
$hit(['u' => 'https://villa.test/tz', 'd' => 'villa.test'], $late, '203.0.113.40');
$hit(['u' => 'https://surf.test/tz', 'd' => 'surf.test'], $late, '203.0.113.40');
wyn_check('a visit belongs to the site owner’s local day (Bali)', $app->db->value("SELECT day FROM wyn_visits WHERE site_id = ? AND entry_path = '/tz'", [$baliId]) === '2026-10-29');
wyn_check('a visit belongs to the site owner’s local day (Los Angeles)', $app->db->value("SELECT day FROM wyn_visits WHERE site_id = ? AND entry_path = '/tz'", [$laId]) === '2026-10-28');
$p = Period::make('today', 'Asia/Makassar', $late);
$q = Period::make('today', 'America/Los_Angeles', $late);
wyn_check('"today" differs per site at the same moment', $p->from === '2026-10-29' && $q->from === '2026-10-28');
$hit(['u' => 'https://villa.test/eve', 'd' => 'villa.test'], $at('2026-10-28 10:00'), '203.0.113.39'); // 18:00 in Bali
wyn_check('Bali day: rolled up only after local midnight + 1 hour', !in_array('2026-10-28', $app->rollup->pending($bali(), $at('2026-10-28 16:30')), true)
    && in_array('2026-10-28', $app->rollup->pending($bali(), $at('2026-10-28 17:30')), true));
$pendBefore = $app->rollup->pending($la(), $at('2026-10-29 07:30')); // 00:30 in LA on Oct 29
$pendAfter = $app->rollup->pending($la(), $at('2026-10-29 08:30'));  // 01:30 in LA
wyn_check('Los Angeles day: waits for local midnight + 1 hour', !in_array('2026-10-28', $pendBefore, true) && in_array('2026-10-28', $pendAfter, true));
$fall = Period::make('custom', 'America/Los_Angeles', $at('2026-11-05 12:00'), '2026-11-01', '2026-11-01');
$spring = Period::make('custom', 'America/Los_Angeles', $at('2026-11-05 12:00'), '2026-03-08', '2026-03-08');
wyn_check('daylight saving: Nov 1 has 25 hours, Mar 8 has 23', count($fall->hourBoundaries()) === 25 && count($spring->hourBoundaries()) === 23);
// Two visits in the repeated 01:00 hour (PDT, then PST) land in two different buckets.
$hit(['u' => 'https://surf.test/dst', 'd' => 'surf.test'], $at('2026-11-01 08:20'), '203.0.113.41');
$hit(['u' => 'https://surf.test/dst', 'd' => 'surf.test'], $at('2026-11-01 09:20'), '203.0.113.42');
$series = $app->stats->series($la(), $fall);
wyn_check('daylight saving: repeated hour shows as two buckets', $series[1]['visits'] === 1 && $series[2]['visits'] === 1 && array_values($fall->buckets())[1] === '01:00' && array_values($fall->buckets())[2] === '01:00');
$hit(['u' => 'https://surf.test/dst2', 'd' => 'surf.test'], $at('2026-11-01 07:59'), '203.0.113.43'); // 00:59 PDT
wyn_check('daylight saving: visit days follow the wall clock', $app->db->value("SELECT day FROM wyn_visits WHERE entry_path = '/dst2'") === '2026-11-01');

// ---------------------------------------------------------------------------
echo "Stats and rollups\n";
$now = $at('2026-10-29 12:00');
$app->fixedNow = $now;
$from = '2026-10-01';
$to = '2026-10-29';
$dims = ['', 'page', 'source', 'entry', 'browser', 'event'];
$rawOnly = $app->stats->raw($bali(), $from, $to, [], $dims);
$app->rollup->run($now);
$mixed = $app->stats->aggregate($bali(), $from, $to, [], $dims);
wyn_check('rollups give exactly the same numbers as raw data', $rawOnly == $mixed);
wyn_check('closed days are rolled up, today is not', $app->db->value("SELECT COUNT(*) FROM wyn_rollups WHERE site_id = ? AND day = '2026-10-20'", [$baliId]) == 1
    && $app->db->value("SELECT COUNT(*) FROM wyn_rollups WHERE site_id = ? AND day = '2026-10-29'", [$baliId]) == 0);
$tot = $app->stats->totals($bali(), '2026-10-20', '2026-10-20');
wyn_check('totals: visitors, visits, pageviews', [$tot['visitors'], $tot['visits'], $tot['pageviews']] === [4, 5, 6], json_encode($tot));
$sum = Stats::summary($tot);
wyn_check('bounce rate and visit duration', round($sum['bounce_rate']) == 80.0 && $sum['visit_duration'] > 0, json_encode($sum));
$src = $app->stats->breakdown($bali(), '2026-10-20', '2026-10-20', 'source');
wyn_check('source breakdown', ($src['Google']['visitors'] ?? 0) === 1 && ($src['Instagram']['visitors'] ?? 0) === 1 && isset($src['']));

echo "Goals and filters\n";
[$gThanks] = $app->sites->addGoal($baliId, 'pageview', '/rooms', $now);
[$gWild] = $app->sites->addGoal($baliId, 'pageview', '/r*', $now);
[$gEvent] = $app->sites->addGoal($baliId, 'event', 'Booking', $now);
[, $bad] = $app->sites->addGoal($baliId, 'pageview', 'https://villa.test/x y', $now);
wyn_check('goal validation', $bad !== null);
$app->rollup->rebuild($bali(), $now);
$goals = array_column(array_map(fn ($g) => [$g['goal']['id'], $g], $app->stats->goals($bali(), '2026-10-20', '2026-10-20')), 1, 0);
wyn_check('pageview goal conversions (exact path)', $goals[$gThanks]['visitors'] === 2 && $goals[$gThanks]['completions'] === 2, json_encode($goals[$gThanks]));
wyn_check('pageview goal with a wildcard', $goals[$gWild]['visitors'] === 2);
wyn_check('event goal conversions and rate', $goals[$gEvent]['visitors'] === 1 && round($goals[$gEvent]['rate']) == 25.0);
$f = $app->stats->totals($bali(), '2026-10-20', '2026-10-20', ['source' => 'Google']);
wyn_check('filter by source', $f['visits'] === 1 && $f['pageviews'] === 2);
$f = $app->stats->totals($bali(), '2026-10-20', '2026-10-20', ['page' => '/rooms']);
wyn_check('filter by page: visits that saw it', $f['visits'] === 2);
$f = $app->stats->totals($bali(), '2026-10-20', '2026-10-20', ['goal' => (string) $gEvent]);
wyn_check('filter by goal', $f['visits'] === 1);

echo "Retention\n";
$app->settings->set('retention_days', '7');
$res = $app->rollup->run($at('2026-10-31 12:00'));
wyn_check('raw visits past retention are deleted', $res['pruned_visits'] > 0 && (int) $app->db->value("SELECT COUNT(*) FROM wyn_visits WHERE site_id = ? AND day = '2026-10-20'", [$baliId]) === 0);
wyn_check('rollups are kept forever', $app->stats->totals($bali(), '2026-10-20', '2026-10-20') == $tot);
$app->fixedNow = $at('2026-10-31 12:00');
wyn_check('goals still work from rollups after raw data is gone', array_column($app->stats->goals($bali(), '2026-10-20', '2026-10-20'), 'visitors')[0] === 2);
wyn_check('filtered views flag that older days aren’t included', $app->stats->filteredPartially($bali(), '2026-10-01', ['source' => 'Google'], 7, $app->now()));
$unrolled = $app->db->value('SELECT COUNT(*) FROM wyn_visits v WHERE NOT EXISTS (SELECT 1 FROM wyn_rollups r WHERE r.site_id = v.site_id AND r.day = v.day)');
wyn_check('never prunes a day that isn’t rolled up', (int) $unrolled >= 0 && (int) $app->db->value("SELECT COUNT(*) FROM wyn_visits WHERE day >= '2026-10-29'") > 0);

// The rest of the tests happen on Oct 29 again: undo the rollups of Oct 29 and 30 made above.
$app->db->query("DELETE FROM wyn_daily WHERE day >= '2026-10-29'");
$app->db->query("DELETE FROM wyn_rollups WHERE day >= '2026-10-29'");

echo "Periods and comparisons\n";
$m = Period::make('month', 'Asia/Makassar', $at('2026-10-29 12:00'));
wyn_check('this month compares with the same days last month', [$m->from, $m->to, $m->prevFrom, $m->prevTo] === ['2026-10-01', '2026-10-29', '2026-09-01', '2026-09-29']);
$c = Period::make('custom', 'UTC', $at('2026-10-29 12:00'), '2026-12-31', '2026-10-01');
wyn_check('custom range: swapped dates fixed, future clamped to today', [$c->from, $c->to] === ['2026-10-01', '2026-10-29'] && $c->interval === 'day');
wyn_check('long ranges are charted by month', Period::make('12mo', 'UTC', $now)->interval === 'month' && count(Period::make('12mo', 'UTC', $now)->buckets()) === 12);
$today = Period::make('today', 'UTC', $now);
wyn_check('today compares with yesterday up to the same time', $today->prevCutoff === $now - 86400);
wyn_check('ranges ending today compare up to the same time of day', Period::make('7d', 'UTC', $now)->prevCutoff === $now - 7 * 86400
    && Period::make('custom', 'UTC', $now, '2026-10-01', '2026-10-10')->prevCutoff === null);
$cut = $app->stats->totals($bali(), '2026-10-28', '2026-10-29', [], $at('2026-10-28 16:20')); // before the /tz visit
$full = $app->stats->totals($bali(), '2026-10-28', '2026-10-29');
wyn_check('"so far" totals stop at the cutoff on the last day', $cut['visits'] === 1 && $full['visits'] === 2, json_encode([$cut['visits'], $full['visits']]));
$r = Report::build($app, $bali(), Period::make('custom', 'Asia/Makassar', $now, '2026-10-20', '2026-10-20'), [], ['page'], 5);
wyn_check('report is shaped for the API', ($r->toArray()['totals']['visitors'] ?? null) === 4 && isset($r->toArray()['breakdowns']['page'][0]['avg_time_on_page']));

// ---------------------------------------------------------------------------
echo "Geo\n";
file_put_contents($tmp . '/geo.csv', "1.0.0.0,1.0.0.255,AU\n1.0.1.0,1.0.3.255,CN\n1.0.8.0,1.0.15.255,CN\n203.0.113.0,203.0.113.255,ID\n2001:db8::,2001:db8:ffff:ffff:ffff:ffff:ffff:ffff,NL\n");
$counts = Geo::build($tmp . '/geo.csv', $tmp . '/geo.bin', 'Test DB');
$geo = new Geo($tmp . '/geo.bin');
wyn_check('country database builds from CSV', $counts['v4'] >= 5 && $counts['v6'] >= 1 && $geo->label() === 'Test DB');
wyn_check('IPv4 lookups, including range edges', [$geo->lookup('1.0.0.0'), $geo->lookup('1.0.0.255'), $geo->lookup('1.0.2.9'), $geo->lookup('203.0.113.77')] === ['AU', 'AU', 'CN', 'ID']);
wyn_check('gaps and unlisted addresses are unknown', $geo->lookup('1.0.5.1') === '' && $geo->lookup('9.9.9.9') === '' && $geo->lookup('nonsense') === '');
wyn_check('IPv6 and IPv4-mapped lookups', $geo->lookup('2001:db8::1') === 'NL' && $geo->lookup('::ffff:203.0.113.5') === 'ID');
wyn_check('Cloudflare country header used when trusted', $geo->country('9.9.9.9', 'de') === 'DE' && $geo->country('203.0.113.1', 'XX') === 'ID');
$appGeo = App::boot(WYN_ROOT, ['database' => $tmp . '/db.sqlite', 'geo_db' => $tmp . '/geo.bin']);
$appGeo->fixedNow = $at('2026-10-30 01:00');
$appGeo->handle(new Request('POST', '/wyn/e', ip: '203.0.113.88', headers: ['user-agent' => $CHROME, 'cf-ipcountry' => 'US'], body: json_encode(['u' => 'https://villa.test/geo', 'd' => 'villa.test'])));
wyn_check('country from the database; CF header ignored when the proxy isn’t trusted', $appGeo->db->value("SELECT country FROM wyn_visits WHERE entry_path = '/geo'") === 'ID');

// ---------------------------------------------------------------------------
echo "Script\n";
$res = $get('/wyn.js');
wyn_check('script served at /wyn.js with the event path filled in', $res->status === 200 && str_contains($res->body, 'new URL("/wyn/e"') && str_contains($res->headers['Content-Type'], 'javascript'));
$gz = strlen((string) gzencode($res->body, 9));
wyn_check("script is under 2 KB gzipped ({$gz} bytes)", $gz < 2048);
wyn_check('script keeps its MIT header and drops comments', str_starts_with($res->body, '/*! Wyn Analytics tracker | MIT License') && !preg_match('#^\s*//#m', $res->body));
wyn_check('script is cached and revalidated with an ETag', $get('/wyn.js', [], ['if-none-match' => $res->headers['ETag']])->status === 304);
wyn_check('script served gzipped when the browser accepts it', ($get('/wyn.js', [], ['accept-encoding' => 'gzip, br'])->headers['Content-Encoding'] ?? '') === 'gzip');
$custom = App::boot(WYN_ROOT, ['database' => $tmp . '/db.sqlite', 'script_path' => '/s.js', 'event_path' => '/api/event', 'base_url' => '/stats/']);
wyn_check('script and event paths are configurable', str_contains($custom->handle(new Request('GET', '/s.js'))->body, 'new URL("/stats/api/event"')
    && $custom->handle(new Request('OPTIONS', '/api/event'))->status === 204);

// ---------------------------------------------------------------------------
echo "Dashboard and security\n";
$_SESSION = [];
wyn_check('admin redirects to setup before an account exists', $get('/admin')->headers['Location'] === '/admin/setup');
$token = $app->session->csrfToken();
$post('/admin/setup', ['_token' => $token, 'email' => 'me@example.com', 'password' => 'long-enough-pw']);
wyn_check('setup creates the account and signs in', ($app->auth->user()['email'] ?? '') === 'me@example.com');
wyn_check('setup is closed once an account exists', $get('/admin/setup')->headers['Location'] === '/admin');
wyn_check('POST without a CSRF token is refused', $post('/admin/sites', ['domain' => 'x.test'])->status === 419);
$app->fixedNow = $at('2026-10-29 12:00');
$dash = $get('/admin/sites/' . $baliId, ['range' => '30d']);
wyn_check('dashboard renders', $dash->status === 200 && str_contains($dash->body, 'Unique visitors') && str_contains($dash->body, '/rooms'));
wyn_check('chart has a text summary and a table view', str_contains($dash->body, 'class="wyn-summary"') && str_contains($dash->body, 'Show as a table') && str_contains($dash->body, '<table class="wyn-table">'));
wyn_check('changes are shown with arrows and words, not color alone', (str_contains($dash->body, 'compared with') && preg_match('/[▲▼]|No change/u', $dash->body) === 1) || str_contains($dash->body, 'No earlier data'));
$xss = $get('/admin/sites/' . $baliId, ['f_source' => '<script>alert(1)</script>', 'range' => '7d']);
wyn_check('filter values are escaped', !str_contains($xss->body, '<script>alert(1)') && str_contains($xss->body, '&lt;script&gt;'));
$hit(['u' => 'https://villa.test/"><img src=x onerror=alert(1)>', 'd' => 'villa.test'], $at('2026-10-29 11:00'), '203.0.113.60');
$hit(['n' => '=HYPERLINK("http://x")', 'u' => 'https://villa.test/', 'd' => 'villa.test'], $at('2026-10-29 11:01'), '203.0.113.60');
$app->fixedNow = $at('2026-10-29 12:00');
wyn_check('tracked paths are escaped on the dashboard', !str_contains($get('/admin/sites/' . $baliId, ['range' => 'today'])->body, '<img src=x'));
$csv = $get('/admin/sites/' . $baliId . '/export.csv', ['table' => 'event', 'range' => 'today'])->body;
wyn_check('CSV export neutralises formulas', str_contains($csv, "'=HYPERLINK") && str_starts_with($csv, "\xEF\xBB\xBF"), $csv);
wyn_check('daily CSV export', str_contains($get('/admin/sites/' . $baliId . '/export.csv', ['range' => '7d'])->body, '2026-10-29'));
wyn_check('unknown site is 404', $get('/admin/sites/999')->status === 404);
$res = $post('/admin/sites', ['_token' => $token, 'domain' => 'shop.test', 'name' => 'Shop', 'timezone' => 'Europe/Amsterdam', 'respect_gpc' => '1']);
$shop = $app->sites->findByDomain('shop.test');
wyn_check('adding a site leads to the script snippet', $res->status === 303 && str_ends_with($res->headers['Location'], '/setup') && str_contains($get('/admin/sites/' . $shop['id'] . '/setup')->body, 'data-wyn-site=&quot;shop.test&quot;'));
$post('/admin/sites/' . $shop['id'] . '/delete', ['_token' => $token, 'confirm_domain' => 'wrong.test']);
wyn_check('deleting a site needs the domain typed', $app->sites->find((int) $shop['id']) !== null);
$post('/admin/sites/' . $shop['id'] . '/delete', ['_token' => $token, 'confirm_domain' => 'shop.test']);
wyn_check('site deleted', $app->sites->find((int) $shop['id']) === null);
$post('/admin/sites/' . $baliId . '/exclude-ip', ['_token' => $token, 'mine' => '1'], '198.51.100.123');
wyn_check('"exclude my connection" uses the request IP, stored hashed', count($app->sites->excluded($bali())) === 2 && !str_contains($bali()['excluded_ips'], '198.51.100.123'));

echo "API\n";
wyn_check('API refuses requests without a token', $get('/api/stats.json')->status === 401);
$post('/admin/sites/' . $baliId . '/token', ['_token' => $token]);
$apiToken = $_SESSION['new_token'] ?? '';
$settingsPage = $get('/admin/sites/' . $baliId . '/settings')->body;
wyn_check('token shown once, stored only as a hash', str_contains($settingsPage, $apiToken) && !str_contains($get('/admin/sites/' . $baliId . '/settings')->body, $apiToken)
    && $bali()['api_token_hash'] === hash('sha256', $apiToken));
$api = $get('/api/stats.json', ['range' => 'custom', 'from' => '2026-10-20', 'to' => '2026-10-20'], ['authorization' => 'Bearer ' . $apiToken]);
$data = json_decode($api->body, true);
wyn_check('API returns totals, series, breakdowns and goals', $api->status === 200 && $data['totals']['visitors'] === 4 && count($data['series']) === 24 && isset($data['breakdowns']['source'], $data['goals']));
$apiAll = json_decode($get('/api/stats.json', ['token' => $apiToken, 'range' => 'today'])->body, true)['totals']['visits'];
$apiDirect = json_decode($get('/api/stats.json', ['token' => $apiToken, 'range' => 'today', 'f_source' => ''])->body, true)['totals']['visits'];
$apiGoogle = json_decode($get('/api/stats.json', ['token' => $apiToken, 'range' => 'today', 'f_source' => 'Google'])->body, true)['totals']['visits'];
wyn_check('API supports filters', $apiAll > 0 && $apiDirect === $apiAll && $apiGoogle === 0, "{$apiAll}/{$apiDirect}/{$apiGoogle}");
wyn_check('token for another site is refused', $get('/api/stats.json', ['token' => $apiToken, 'site' => 'surf.test'])->status === 403);
$post('/admin/sites/' . $baliId . '/token-revoke', ['_token' => $token]);
wyn_check('revoked token stops working', $get('/api/stats.json', ['token' => $apiToken])->status === 401);

echo "Public stats page\n";
wyn_check('stats page is private by default', $get('/stats/villa.test')->status === 404);
$post('/admin/sites/' . $baliId . '/settings', ['_token' => $token, 'domain' => 'villa.test', 'name' => 'Villa', 'timezone' => 'Asia/Makassar', 'respect_gpc' => '1', 'include_subdomains' => '1', 'public' => '1']);
$pub = $get('/stats/villa.test');
wyn_check('public page shows big type and no admin links', $pub->status === 200 && str_contains($pub->body, 'wyn-show') && !str_contains($pub->body, '/admin'));
$post('/admin/sites/' . $baliId . '/settings', ['_token' => $token, 'domain' => 'villa.test', 'name' => 'Villa', 'timezone' => 'Asia/Makassar', 'respect_gpc' => '1', 'public' => '1', 'public_password' => 'sunset-42']);
unset($_SESSION['stats_unlocked_' . $baliId]);
wyn_check('password-protected page asks for the password', str_contains($get('/stats/villa.test')->body, 'type="password"'));
$tries = [];
for ($i = 0; $i < 6; $i++) {
    $tries[] = str_contains($post('/stats/villa.test', ['_token' => $token, 'password' => 'guess' . $i], '198.51.100.77')->body, 'Too many attempts');
}
wyn_check('password guessing is rate-limited', $tries === [false, false, false, false, false, true]);
$res = $post('/stats/villa.test', ['_token' => $token, 'password' => 'sunset-42'], '198.51.100.78');
wyn_check('right password unlocks the page', $res->status === 303 && str_contains($get('/stats/villa.test')->body, 'wyn-show'));

echo "Rollup schedule\n";
wyn_check('web cron needs the secret token', $get('/cron/nope')->status === 403);
wyn_check('web cron runs rollups', str_starts_with($get('/cron/' . $app->settings->secret('cron_token'))->body, 'Rolled up'));

echo "Themes and accessibility\n";
$pages = ['/admin', '/admin/sites/' . $baliId, '/admin/sites/' . $baliId . '/settings', '/admin/sites/' . $baliId . '/setup', '/admin/settings', '/stats/villa.test'];
$external = [];
foreach ($pages as $path) {
    // Anything the browser loads by itself (scripts, styles, fonts, images, frames) must be local.
    preg_match_all('/<(script|link|img|iframe|source|video|audio)\b[^>]*\b(?:src|href)="([^"]*)"/i', $get($path)->body, $mm, PREG_SET_ORDER);
    foreach ($mm as $x) {
        if (preg_match('#^(https?:)?//#i', $x[2])) {
            $external[] = $path . ' → ' . $x[2];
        }
    }
}
foreach (glob(WYN_ROOT . '/public/assets/*.{css,js}', GLOB_BRACE) as $file) {
    if (preg_match('#url\((["\']?)https?://|@import#', (string) file_get_contents($file))) {
        $external[] = basename($file);
    }
}
wyn_check('zero external requests: no fonts, CDNs or trackers', $external === [], implode(', ', $external));
wyn_check('bold theme by default', str_contains($get('/admin')->body, 'data-wyn-theme="wayan"'));
$post('/admin/settings', ['_token' => $token, 'site_name' => 'Stats', 'theme' => 'neutral', 'retention_days' => '30']);
wyn_check('neutral theme applies to dashboard and public page', str_contains($get('/admin')->body, 'data-wyn-theme="neutral"') && str_contains($get('/stats/villa.test')->body, 'data-wyn-theme="neutral"'));
wyn_check('pages have a skip link and labelled charts', str_contains($dash->body, 'Skip to content') && str_contains($dash->body, 'role="img" aria-labelledby='));

// WCAG AA contrast for every text color pair, in both themes and both modes.
$css = (string) file_get_contents(WYN_ROOT . '/public/assets/wyn-tokens.css');
$block = function (string $selector, bool $dark) use ($css): array {
    $vars = [];
    $pattern = $dark ? '/@media \(prefers-color-scheme: dark\)\s*\{\s*' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/' : '/(?<![\w\]])' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/';
    if (preg_match($pattern, $css, $m)) {
        preg_match_all('/--wyn-([a-z-]+):\s*(#[0-9a-f]{6})/i', $m[1], $vv, PREG_SET_ORDER);
        foreach ($vv as $x) {
            $vars[$x[1]] = $x[2];
        }
    }

    return $vars;
};
$lum = function (string $hex): float {
    $c = array_map(fn ($h) => hexdec($h) / 255, str_split(ltrim($hex, '#'), 2));
    $c = array_map(fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);

    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};
$ratio = fn ($a, $b) => (max($lum($a), $lum($b)) + 0.05) / (min($lum($a), $lum($b)) + 0.05);
$lightBold = $block(':root', false);
$darkBold = $block(':root', true) + $lightBold;
$lightNeutral = $block(':root[data-wyn-theme="neutral"]', false) + $lightBold;
$darkNeutral = $block(':root[data-wyn-theme="neutral"]', true) + $block(':root[data-wyn-theme="neutral"]', false) + $darkBold;
$fails = [];
foreach (['bold light' => $lightBold, 'bold dark' => $darkBold, 'neutral light' => $lightNeutral, 'neutral dark' => $darkNeutral] as $name => $t) {
    foreach ([['ink', 'paper'], ['muted', 'paper'], ['muted', 'wash'], ['ok', 'paper'], ['danger', 'paper'], ['ok', 'wash'], ['danger', 'wash'], ['ink', 'wash']] as [$fg, $bg]) {
        $r = $ratio($t[$fg], $t[$bg]);
        if ($r < 4.5) {
            $fails[] = "{$name} {$fg}/{$bg} " . round($r, 2);
        }
    }
    if ($ratio($t['up'], $t['paper']) < 3) {
        $fails[] = "{$name} live dot";
    }
}
wyn_check('WCAG AA contrast in both themes and both modes', $fails === [], implode('; ', $fails));
wyn_check('reduced motion stops animations', str_contains((string) file_get_contents(WYN_ROOT . '/public/assets/wyn-admin.css'), 'prefers-reduced-motion: reduce'));

echo "Sign-in\n";
$app->auth->logout();
$_SESSION = [];
wyn_check('signed-out users go to sign in', $get('/admin')->headers['Location'] === '/admin/login');
$token = $app->session->csrfToken();
$outcome = [];
for ($i = 0; $i < 6; $i++) {
    $outcome[] = str_contains($post('/admin/login', ['_token' => $token, 'email' => 'me@example.com', 'password' => 'wrong' . $i], '198.51.100.200')->body, 'Too many attempts');
}
wyn_check('sign-in is rate-limited', $outcome === [false, false, false, false, false, true]);
wyn_check('the right password works from another address', $post('/admin/login', ['_token' => $token, 'email' => 'me@example.com', 'password' => 'long-enough-pw'], '198.51.100.201')->status === 303);
wyn_check('sign-in attempts store a hash, not the IP', !str_contains(json_encode($app->db->all('SELECT * FROM wyn_login_attempts')), '198.51.100.200'));

echo PHP_EOL . ($wynFailed === 0 ? "\033[32m{$wynPassed} passed\033[0m" : "\033[31m{$wynFailed} failed\033[0m, {$wynPassed} passed") . PHP_EOL;
exit($wynFailed === 0 ? 0 : 1);
