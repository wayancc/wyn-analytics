<?php

/*
 * Copy to config.php to change server settings. Sites, goals, the theme and
 * retention are edited in the dashboard.
 */
return [
    // SQLite database file. Keep it outside public/.
    'database'    => 'storage/wyn-analytics.sqlite',

    // Full address of this install, used for the script tag and links.
    'app_url'     => 'https://stats.example.com',

    // Path prefix when installed in a subfolder, e.g. '/stats/'.
    'base_url'    => '/',

    // Default time zone for new sites and the dashboard, e.g. 'Asia/Makassar'.
    'timezone'    => 'UTC',

    // Behind Cloudflare or a reverse proxy? Set true to read the real visitor IP
    // (X-Forwarded-For / CF-Connecting-IP) and the CF-IPCountry header.
    'trust_proxy' => false,

    // Where the script is served and events are sent. Words like "analytics" or
    // "track" in these paths get blocked by more ad blockers.
    'script_path' => '/wyn.js',
    'event_path'  => '/wyn/e',

    // Country database built by: php bin/wyn-analytics geo:update
    'geo_db'      => 'storage/wyn-geo.bin',
];
