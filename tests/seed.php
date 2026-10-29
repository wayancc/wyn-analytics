<?php

declare(strict_types=1);

/*
 * Demo data for screenshots and local testing. Sends realistic traffic through the real
 * tracking endpoint (bots, GPC and all), with time moved back day by day.
 *
 *   php tests/seed.php --database=storage/demo.sqlite --days=60 [--domain=wayan.cc] [--per-day=140]
 *
 * Never run it against a database with real stats.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use WynAnalytics\App;
use WynAnalytics\Http\Request;

$opts = getopt('', ['database:', 'days::', 'domain::', 'per-day::', 'email::', 'password::']);
if (empty($opts['database'])) {
    fwrite(STDERR, "Usage: php tests/seed.php --database=storage/demo.sqlite [--days=60] [--domain=wayan.cc]\n");
    exit(1);
}
$days = (int) ($opts['days'] ?? 60);
$domain = (string) ($opts['domain'] ?? 'wayan.cc');
$perDay = (int) ($opts['per-day'] ?? 140);
mt_srand(20261029);

$app = App::boot(WYN_ROOT, ['database' => $opts['database'], 'trust_proxy' => true]);
if (!$app->auth->hasUsers()) {
    $app->auth->createUser((string) ($opts['email'] ?? 'demo@wayan.cc'), (string) ($opts['password'] ?? 'demo-password-123'));
    $app->settings->set('site_name', 'Wyn Analytics');
}
$site = $app->sites->findByDomain($domain);
if ($site === null) {
    [$id] = $app->sites->save(['domain' => $domain, 'name' => $domain, 'timezone' => 'Asia/Makassar', 'respect_gpc' => 1, 'include_subdomains' => 1, 'public' => 1]);
    $site = $app->sites->find((int) $id);
}
$sid = (int) $site['id'];
$start = time() - $days * 86400;
foreach ([['pageview', '/thanks'], ['event', 'Start a project'], ['event', 'Form submitted'], ['pageview', '/work/*']] as [$k, $val]) {
    $app->sites->addGoal($sid, $k, $val, $start);
}

$pick = function (array $weighted) {
    $r = mt_rand(1, array_sum($weighted));
    foreach ($weighted as $value => $w) {
        if (($r -= $w) <= 0) {
            return (string) $value;
        }
    }

    return (string) array_key_first($weighted);
};

$sources = [
    '' => 34, 'https://www.google.com/' => 30, 'https://l.instagram.com/' => 12, 'https://www.facebook.com/' => 5,
    'https://chatgpt.com/' => 4, 'https://www.linkedin.com/' => 4, 'https://www.tripadvisor.com/' => 3, 'https://www.bing.com/' => 3,
    'https://github.com/wayancc' => 3, 'https://duckduckgo.com/' => 2, 'https://news.ycombinator.com/' => 1, 'utm:newsletter' => 3,
];
$countries = ['ID' => 30, 'AU' => 18, 'US' => 12, 'GB' => 7, 'DE' => 6, 'NL' => 5, 'SG' => 5, 'FR' => 4, 'JP' => 3, 'CA' => 3, 'MY' => 2, 'IN' => 2];
$agents = [
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1|390' => 34,
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15|1512' => 18,
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36|1920' => 20,
    'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36|412' => 14,
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0|1366' => 5,
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.6; rv:132.0) Gecko/20100101 Firefox/132.0|1440' => 5,
    'Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1|820' => 4,
];
$entries = ['/' => 46, '/work' => 12, '/work/villa-samsara' => 9, '/work/kopi-kultur' => 6, '/services' => 9, '/about' => 5, '/journal/fast-websites-for-hotels' => 9, '/start-a-project' => 4];
$next = ['/' => ['/work' => 4, '/services' => 3, '/about' => 2, '/start-a-project' => 1], '/work' => ['/work/villa-samsara' => 3, '/work/kopi-kultur' => 2, '/start-a-project' => 1],
    '/services' => ['/start-a-project' => 2, '/work' => 1], '/start-a-project' => ['/thanks' => 1]];

$send = function (array $body, string $ip, string $ua, string $cc, int $ts) use ($app, $domain) {
    $app->fixedNow = $ts;
    $app->handle(new Request('POST', '/wyn/e', ip: $ip, headers: ['user-agent' => $ua, 'cf-ipcountry' => $cc], body: json_encode($body + ['d' => $domain])));
};

$tz = new DateTimeZone('Asia/Makassar');
$visits = 0;
for ($d = $days; $d >= 0; $d--) {
    $dayStart = (new DateTimeImmutable('@' . (time() - $d * 86400)))->setTimezone($tz)->setTime(0, 0)->getTimestamp();
    $weekday = (int) date('N', $dayStart);
    $trend = 0.75 + 0.5 * (($days - $d) / max(1, $days));          // growing over time
    $n = (int) round($perDay * $trend * ($weekday >= 6 ? 0.72 : 1.0) * (0.85 + mt_rand(0, 30) / 100));
    if ($d === 12) {
        $n = (int) ($n * 2.4); // a launch day spike
    }
    $today = [];
    for ($i = 0; $i < $n; $i++) {
        // Busier in the Bali daytime and the Australian/European evenings.
        $hour = (int) $pick([0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 2, 6 => 3, 7 => 4, 8 => 6, 9 => 8, 10 => 8, 11 => 7, 12 => 6, 13 => 7, 14 => 8, 15 => 8, 16 => 7, 17 => 6, 18 => 6, 19 => 7, 20 => 7, 21 => 6, 22 => 4, 23 => 2]);
        $ts = $dayStart + $hour * 3600 + mt_rand(0, 3599);
        if ($ts > time()) {
            continue;
        }
        if ($today !== [] && mt_rand(1, 100) <= 14) {
            [$ua, $width, $ip, $cc] = $today[array_rand($today)]; // the same person, back later that day
        } else {
            [$ua, $width] = explode('|', $pick($agents));
            $ip = mt_rand(1, 223) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254);
            $cc = $pick($countries);
            $today[] = [$ua, $width, $ip, $cc];
        }
        $src = $pick($sources);
        $ref = str_starts_with($src, 'utm:') ? '' : $src;
        $path = $pick($entries);
        $query = str_starts_with($src, 'utm:') ? '?utm_source=newsletter&utm_medium=email&utm_campaign=october' : ($src === 'https://l.instagram.com/' && mt_rand(0, 1) ? '?utm_source=ig&utm_medium=social&utm_campaign=portfolio' : '');
        $send(['n' => 'pageview', 'u' => "https://{$domain}{$path}{$query}", 'r' => $ref, 'w' => (int) $width], $ip, $ua, $cc, $ts);
        $visits++;

        $pages = mt_rand(1, 100) <= 52 ? 1 : mt_rand(2, 5);
        for ($p = 1; $p < $pages; $p++) {
            $read = mt_rand(15, 140);
            $send(['n' => 'engagement', 'u' => "https://{$domain}{$path}", 'e' => $read * 1000], $ip, $ua, $cc, $ts += $read);
            if ($path === '/start-a-project' && mt_rand(1, 100) <= 45) {
                $send(['n' => 'Form submitted', 'u' => "https://{$domain}{$path}", 'p' => ['form' => 'project']], $ip, $ua, $cc, $ts += 5);
            }
            $options = $next[$path] ?? ['/' => 2, '/work' => 1, '/start-a-project' => 1];
            if (mt_rand(1, 100) <= 18 && $path !== '/start-a-project') {
                $send(['n' => 'Start a project', 'u' => "https://{$domain}{$path}", 'p' => ['place' => $path === '/' ? 'Hero' : 'Footer']], $ip, $ua, $cc, $ts += 2);
                $options = ['/start-a-project' => 1];
            }
            $path = $pick($options);
            $send(['n' => 'pageview', 'u' => "https://{$domain}{$path}", 'r' => "https://{$domain}/"], $ip, $ua, $cc, $ts += 2);
        }
        if (mt_rand(1, 100) <= 9) {
            $send(['n' => 'Outbound link', 'u' => "https://{$domain}{$path}", 'p' => ['url' => $pick(['https://www.instagram.com/wayan.cc' => 3, 'https://github.com/wayancc' => 2, 'https://villasamsara.com/' => 2])]], $ip, $ua, $cc, $ts + 3);
        }
        $send(['n' => 'engagement', 'u' => "https://{$domain}{$path}", 'e' => mt_rand(4, 90) * 1000], $ip, $ua, $cc, $ts + mt_rand(5, 60));
    }
}

// A few people on the site right now.
for ($i = 0; $i < 4; $i++) {
    $send(['n' => 'pageview', 'u' => "https://{$domain}/work", 'r' => 'https://www.google.com/', 'w' => 1440], "198.51.100.{$i}", explode('|', (string) array_key_first($agents))[0], 'AU', time() - mt_rand(10, 200));
}
$app->fixedNow = null;
$r = $app->rollup->run(time());
echo "Seeded {$visits} visits over {$days} days for {$domain}; rolled up {$r['rolled']} days.\n";
