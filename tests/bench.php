<?php

declare(strict_types=1);

/*
 * Load benchmark: php tests/bench.php [--events=100000] [--sites=3]
 *
 * Sends events through the real tracking code (in-process, no web server), then times a
 * rollup and the dashboard queries, and reports the database size. Run it on your own host
 * to see what it can handle; the numbers in the README come from this script.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use WynAnalytics\App;
use WynAnalytics\Http\Request;
use WynAnalytics\Period;
use WynAnalytics\Report;

$opts = getopt('', ['events::', 'sites::']);
$total = (int) ($opts['events'] ?? 100_000);
$sites = max(1, (int) ($opts['sites'] ?? 3));
$dir = sys_get_temp_dir() . '/wyn-analytics-bench-' . bin2hex(random_bytes(3));
mkdir($dir);
$db = $dir . '/bench.sqlite';
$app = App::boot(WYN_ROOT, ['database' => $db]);
for ($i = 1; $i <= $sites; $i++) {
    $app->sites->save(['domain' => "site{$i}.test", 'timezone' => 'Asia/Makassar', 'respect_gpc' => 1, 'include_subdomains' => 1]);
}
$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
$pages = ['/', '/about', '/pricing', '/blog', '/blog/one', '/blog/two', '/contact', '/docs', '/docs/install', '/thanks'];
$refs = ['', '', 'https://www.google.com/', 'https://l.instagram.com/', 'https://news.ycombinator.com/', 'https://chatgpt.com/'];
mt_srand(7);

// Spread the events over the last 14 days, about 3 pageviews per visit.
$start = time() - 14 * 86400;
$t0 = microtime(true);
$visitors = (int) ($total / 3);
for ($n = 0; $n < $total;) {
    $site = 'site' . mt_rand(1, $sites) . '.test';
    $ip = '10.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);
    $ts = $start + (int) ($n / $total * 14 * 86400);
    $views = mt_rand(1, 5);
    for ($k = 0; $k < $views && $n < $total; $k++, $n++) {
        $app->fixedNow = $ts + $k * 40;
        $body = ['u' => "https://{$site}" . $pages[mt_rand(0, 9)], 'd' => $site, 'r' => $k === 0 ? $refs[mt_rand(0, 5)] : '', 'w' => 1280];
        if ($k > 0 && mt_rand(0, 4) === 0) {
            $body = ['n' => 'Signup', 'u' => "https://{$site}/pricing", 'd' => $site, 'p' => ['plan' => 'pro']];
        }
        $app->handle(new Request('POST', '/wyn/e', ip: $ip, headers: ['user-agent' => $ua], body: json_encode($body)));
    }
}
$ingest = microtime(true) - $t0;
$app->fixedNow = null;
$app->db->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
clearstatcache();
$rawSize = filesize($db);

$t1 = microtime(true);
$rolled = $app->rollup->run(time())['rolled'];
$rollup = microtime(true) - $t1;

$site = $app->sites->findByDomain('site1.test');
$t2 = microtime(true);
Report::build($app, $site, Period::make('30d', $site['timezone'], time()), [], array_keys(Report::DIMS));
$dash = microtime(true) - $t2;
$t3 = microtime(true);
Report::build($app, $site, Period::make('30d', $site['timezone'], time()), ['source' => 'Google'], array_keys(Report::DIMS));
$filtered = microtime(true) - $t3;
$app->db->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
clearstatcache();

printf("Events:            %s (%d sites, 14 days)\n", number_format($total), $sites);
printf("Ingest:            %s events/second (%.1f s, single process, in-process)\n", number_format($total / $ingest), $ingest);
printf("Database size:     %.1f MB raw (%.1f MB per 100k events)\n", $rawSize / 1048576, $rawSize / 1048576 / $total * 100_000);
printf("Rollup:            %d site-days in %.2f s\n", $rolled, $rollup);
printf("Dashboard, 30 days: %.0f ms (all panels)\n", $dash * 1000);
printf("Dashboard, filtered: %.0f ms (all panels, from raw data)\n", $filtered * 1000);
printf("PHP %s, SQLite %s\n", PHP_VERSION, $app->db->pdo->query('select sqlite_version()')->fetchColumn());
exec('rm -rf ' . escapeshellarg($dir));
