<?php

declare(strict_types=1);

namespace WynAnalytics;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper over a single SQLite file, with versioned migrations.
 */
final class Database
{
    public readonly PDO $pdo;

    public function __construct(string $path)
    {
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create the storage folder: {$dir}");
            }
            if (!is_writable($dir)) {
                throw new \RuntimeException("The storage folder isn't writable by PHP: {$dir}");
            }
        }

        $this->pdo = new PDO('sqlite:' . $path, options: [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        // Switching a new file to WAL can fail with "locked" without waiting when another process
        // opens it at the same moment (worker and web on first start). It sticks once set, so retry briefly.
        for ($try = 1; ; $try++) {
            try {
                $this->pdo->exec('PRAGMA journal_mode = WAL');
                break;
            } catch (\PDOException $e) {
                if ($try >= 50) {
                    throw $e;
                }
                usleep(100_000);
            }
        }
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->migrate();
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        // Bind with real types: SQLite compares an integer with a string-bound value as text
        // inside expressions such as COALESCE(), which silently breaks time-range queries.
        foreach ($params as $key => $value) {
            $type = match (true) {
                is_int($value), is_bool($value) => PDO::PARAM_INT,
                $value === null                 => PDO::PARAM_NULL,
                default                         => PDO::PARAM_STR,
            };
            $stmt->bindValue(is_int($key) ? $key + 1 : ':' . ltrim($key, ':'), $value, $type);
        }
        $stmt->execute();

        return $stmt;
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array> */
    public function all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $this->query(
            sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $cols), implode(', ', array_map(fn ($c) => ':' . $c, $cols))),
            $data,
        );

        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(', ', array_map(fn ($c) => "{$c} = :set_{$c}", array_keys($data)));
        $bound = [];
        foreach ($data as $k => $v) {
            $bound['set_' . $k] = $v;
        }

        return $this->query("UPDATE {$table} SET {$set} WHERE {$where}", $bound + $params)->rowCount();
    }

    /**
     * Write transaction that takes the lock up front. A deferred transaction that later
     * upgrades to a write can fail with "database is locked" without waiting under load.
     */
    public function immediate(callable $fn): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn($this);
            $this->pdo->exec('COMMIT');

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function migrate(): void
    {
        $migrations = [
            1 => <<<'SQL'
                CREATE TABLE wyn_settings (
                    key   TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                );
                CREATE TABLE wyn_users (
                    id            INTEGER PRIMARY KEY,
                    email         TEXT NOT NULL UNIQUE,
                    password_hash TEXT NOT NULL,
                    created_at    INTEGER NOT NULL
                );
                -- Failed sign-ins and public-page password tries. "who" is a salted hash, never an IP.
                CREATE TABLE wyn_login_attempts (
                    who          TEXT NOT NULL,
                    attempted_at INTEGER NOT NULL
                );
                CREATE TABLE wyn_sites (
                    id                 INTEGER PRIMARY KEY,
                    domain             TEXT NOT NULL UNIQUE,           -- example.com (what data-wyn-site says)
                    name               TEXT NOT NULL,
                    timezone           TEXT NOT NULL DEFAULT 'UTC',    -- "today" means the owner's day
                    respect_gpc        INTEGER NOT NULL DEFAULT 1,     -- skip visitors with Global Privacy Control on
                    include_subdomains INTEGER NOT NULL DEFAULT 1,     -- count blog.example.com as example.com
                    excluded_ips       TEXT NOT NULL DEFAULT '[]',     -- JSON: [{hash, label, added_at}], never raw IPs
                    public             INTEGER NOT NULL DEFAULT 0,     -- public stats page at /stats/{domain}
                    public_password    TEXT NOT NULL DEFAULT '',       -- password_hash() or empty
                    api_token_hash     TEXT NOT NULL DEFAULT '',       -- sha256 of the read token
                    api_token_hint     TEXT NOT NULL DEFAULT '',       -- last 4 characters, to recognise it
                    first_event_at     INTEGER,
                    created_at         INTEGER NOT NULL
                );
                CREATE TABLE wyn_goals (
                    id         INTEGER PRIMARY KEY,
                    site_id    INTEGER NOT NULL REFERENCES wyn_sites(id) ON DELETE CASCADE,
                    kind       TEXT NOT NULL,                          -- pageview | event
                    value      TEXT NOT NULL,                          -- /thanks, /blog/* or an event name
                    created_at INTEGER NOT NULL,
                    UNIQUE (site_id, kind, value)
                );
                -- One random salt per calendar date. Salts older than yesterday are deleted,
                -- so yesterday's visitor IDs can never be recomputed or linked to today's.
                CREATE TABLE wyn_salts (
                    day  TEXT PRIMARY KEY,
                    salt TEXT NOT NULL
                );
                -- Raw data (kept for the retention period, 30 days by default).
                CREATE TABLE wyn_visits (
                    id           INTEGER PRIMARY KEY,
                    site_id      INTEGER NOT NULL REFERENCES wyn_sites(id) ON DELETE CASCADE,
                    visitor      TEXT NOT NULL,                        -- daily-salted hash, not a person
                    day          TEXT NOT NULL,                        -- YYYY-MM-DD in the site's time zone
                    started_at   INTEGER NOT NULL,
                    last_at      INTEGER NOT NULL,
                    entry_path   TEXT NOT NULL,
                    exit_path    TEXT NOT NULL,
                    pageviews    INTEGER NOT NULL DEFAULT 0,
                    events       INTEGER NOT NULL DEFAULT 0,
                    engaged_ms   INTEGER NOT NULL DEFAULT 0,
                    source       TEXT NOT NULL DEFAULT '',             -- Google, Instagram, or a host; '' = direct
                    referrer     TEXT NOT NULL DEFAULT '',             -- referring host
                    utm_source   TEXT NOT NULL DEFAULT '',
                    utm_medium   TEXT NOT NULL DEFAULT '',
                    utm_campaign TEXT NOT NULL DEFAULT '',
                    country      TEXT NOT NULL DEFAULT '',             -- ISO 3166 alpha-2, '' = unknown
                    device       TEXT NOT NULL DEFAULT '',             -- Desktop | Mobile | Tablet
                    browser      TEXT NOT NULL DEFAULT '',
                    os           TEXT NOT NULL DEFAULT '',
                    screen       TEXT NOT NULL DEFAULT ''              -- Phone | Tablet | Laptop | Desktop (by width)
                );
                CREATE INDEX wyn_visits_visitor ON wyn_visits (site_id, visitor, last_at);
                CREATE INDEX wyn_visits_day ON wyn_visits (site_id, day);
                CREATE INDEX wyn_visits_last ON wyn_visits (site_id, last_at);
                CREATE TABLE wyn_events (
                    id         INTEGER PRIMARY KEY,
                    site_id    INTEGER NOT NULL REFERENCES wyn_sites(id) ON DELETE CASCADE,
                    visit_id   INTEGER NOT NULL REFERENCES wyn_visits(id) ON DELETE CASCADE,
                    day        TEXT NOT NULL,
                    ts         INTEGER NOT NULL,
                    type       TEXT NOT NULL,                          -- pageview | event
                    name       TEXT NOT NULL DEFAULT '',               -- event name ('' for pageviews)
                    path       TEXT NOT NULL,
                    props      TEXT,                                   -- JSON object, events only
                    engaged_ms INTEGER NOT NULL DEFAULT 0
                );
                CREATE INDEX wyn_events_day ON wyn_events (site_id, day, type);
                CREATE INDEX wyn_events_visit ON wyn_events (visit_id);
                -- Daily rollups, kept forever. dim '' holds the day's totals.
                CREATE TABLE wyn_daily (
                    site_id   INTEGER NOT NULL REFERENCES wyn_sites(id) ON DELETE CASCADE,
                    day       TEXT NOT NULL,
                    dim       TEXT NOT NULL,
                    value     TEXT NOT NULL,
                    visitors  INTEGER NOT NULL DEFAULT 0,
                    visits    INTEGER NOT NULL DEFAULT 0,
                    pageviews INTEGER NOT NULL DEFAULT 0,
                    bounces   INTEGER NOT NULL DEFAULT 0,
                    duration  INTEGER NOT NULL DEFAULT 0,               -- seconds, summed over visits
                    events    INTEGER NOT NULL DEFAULT 0,
                    PRIMARY KEY (site_id, day, dim, value)
                ) WITHOUT ROWID;
                CREATE TABLE wyn_rollups (
                    site_id   INTEGER NOT NULL REFERENCES wyn_sites(id) ON DELETE CASCADE,
                    day       TEXT NOT NULL,
                    rolled_at INTEGER NOT NULL,
                    PRIMARY KEY (site_id, day)
                ) WITHOUT ROWID;
                SQL,
        ];

        $version = fn (): int => (int) $this->pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version() >= array_key_last($migrations)) {
            return;
        }

        // The worker and the first web request can start at the same moment (Docker).
        // Take the write lock first, then re-read the version, so only one of them migrates.
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $current = $version();
            foreach ($migrations as $v => $sql) {
                if ($v > $current) {
                    $this->pdo->exec($sql);
                    $this->pdo->exec('PRAGMA user_version = ' . $v);
                }
            }
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }
}
