<?php

declare(strict_types=1);

namespace WynAnalytics;

/**
 * Sites and their goals: validation, lookups and tokens.
 */
final class Sites
{
    public function __construct(private readonly Database $db, private readonly Settings $settings)
    {
    }

    /** @return list<array> */
    public function all(): array
    {
        return $this->db->all('SELECT * FROM wyn_sites ORDER BY name COLLATE NOCASE');
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM wyn_sites WHERE id = ?', [$id]);
    }

    public function findByDomain(string $domain): ?array
    {
        return $this->db->one('SELECT * FROM wyn_sites WHERE domain = ?', [self::normaliseDomain($domain)]);
    }

    public function defaults(): array
    {
        return [
            'id' => null, 'domain' => '', 'name' => '', 'timezone' => (string) date_default_timezone_get(),
            'respect_gpc' => 1, 'include_subdomains' => 1, 'public' => 0, 'public_password' => '',
        ];
    }

    /**
     * Creates or updates a site from form input.
     *
     * @return array{0: ?int, 1: array<string, string>, 2: array}
     */
    public function save(array $in, ?int $id = null): array
    {
        $errors = [];
        $domain = self::normaliseDomain((string) ($in['domain'] ?? ''));
        $data = [
            'domain'             => $domain,
            'name'               => mb_substr(trim((string) ($in['name'] ?? '')), 0, 60) ?: $domain,
            'timezone'           => trim((string) ($in['timezone'] ?? 'UTC')),
            'respect_gpc'        => !empty($in['respect_gpc']) ? 1 : 0,
            'include_subdomains' => !empty($in['include_subdomains']) ? 1 : 0,
            'public'             => !empty($in['public']) ? 1 : 0,
        ];

        if (!self::validDomain($domain)) {
            $errors['domain'] = 'Enter a domain like example.com, without https:// or a path.';
        } elseif ($this->db->value('SELECT id FROM wyn_sites WHERE domain = ? AND id != ?', [$domain, $id ?? 0]) !== null) {
            $errors['domain'] = 'This domain is already added.';
        }
        if (!in_array($data['timezone'], \DateTimeZone::listIdentifiers(), true) && $data['timezone'] !== 'UTC') {
            $errors['timezone'] = 'Choose a time zone from the list.';
        }

        $password = (string) ($in['public_password'] ?? '');
        if ($password !== '' && strlen($password) < 6) {
            $errors['public_password'] = 'Use at least 6 characters, or leave it empty for no password.';
        }
        if ($errors !== []) {
            return [null, $errors, $data];
        }
        if ($password !== '') {
            $data['public_password'] = password_hash($password, PASSWORD_DEFAULT);
        }
        if (!empty($in['remove_password'])) {
            $data['public_password'] = '';
        }

        if ($id === null) {
            $id = $this->db->insert('wyn_sites', $data + ['created_at' => time()]);
        } else {
            $this->db->update('wyn_sites', $data, 'id = :id', ['id' => $id]);
        }

        return [$id, [], $data];
    }

    public function delete(int $id): void
    {
        // Delete the big tables first and explicitly: faster than relying on cascades.
        $this->db->immediate(function (Database $db) use ($id) {
            $db->query('DELETE FROM wyn_events WHERE site_id = ?', [$id]);
            $db->query('DELETE FROM wyn_visits WHERE site_id = ?', [$id]);
            $db->query('DELETE FROM wyn_daily WHERE site_id = ?', [$id]);
            $db->query('DELETE FROM wyn_sites WHERE id = ?', [$id]);
        });
    }

    // ------------------------------------------------------------------ API token

    /** Makes a new read token and returns it. Only its hash is stored, so it's shown once. */
    public function newToken(int $id): string
    {
        $token = 'wyn_' . bin2hex(random_bytes(20));
        $this->db->update('wyn_sites', ['api_token_hash' => hash('sha256', $token), 'api_token_hint' => substr($token, -4)], 'id = :id', ['id' => $id]);

        return $token;
    }

    public function revokeToken(int $id): void
    {
        $this->db->update('wyn_sites', ['api_token_hash' => '', 'api_token_hint' => ''], 'id = :id', ['id' => $id]);
    }

    public function findByToken(string $token): ?array
    {
        if (!str_starts_with($token, 'wyn_') || strlen($token) !== 44) {
            return null;
        }

        return $this->db->one('SELECT * FROM wyn_sites WHERE api_token_hash = ?', [hash('sha256', $token)]);
    }

    // ------------------------------------------------------------------ Excluded IPs

    /** @return list<array{hash: string, label: string, added_at: int}> */
    public function excluded(array $site): array
    {
        $list = json_decode((string) $site['excluded_ips'], true);

        return is_array($list) ? $list : [];
    }

    /** Adds an IP to the exclude list. Only its keyed hash is stored. */
    public function excludeIp(array $site, string $ip, string $label): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return 'Enter a single IPv4 or IPv6 address.';
        }
        $hash = $this->settings->hash(inet_ntop((string) inet_pton($ip)) ?: $ip);
        $list = array_values(array_filter($this->excluded($site), fn ($e) => $e['hash'] !== $hash));
        $list[] = ['hash' => $hash, 'label' => mb_substr(trim($label), 0, 40) ?: 'IP address', 'added_at' => time()];
        $this->db->update('wyn_sites', ['excluded_ips' => json_encode(array_slice($list, -50))], 'id = :id', ['id' => $site['id']]);

        return null;
    }

    public function removeExcluded(array $site, string $hash): void
    {
        $list = array_values(array_filter($this->excluded($site), fn ($e) => $e['hash'] !== $hash));
        $this->db->update('wyn_sites', ['excluded_ips' => json_encode($list)], 'id = :id', ['id' => $site['id']]);
    }

    public function isExcluded(array $site, string $ip): bool
    {
        $list = $this->excluded($site);
        if ($list === []) {
            return false;
        }
        $hash = $this->settings->hash(inet_ntop((string) @inet_pton($ip)) ?: $ip);

        return in_array($hash, array_column($list, 'hash'), true);
    }

    // ------------------------------------------------------------------ Goals

    /** @return list<array> */
    public function goals(int $siteId): array
    {
        return $this->db->all('SELECT * FROM wyn_goals WHERE site_id = ? ORDER BY kind DESC, value COLLATE NOCASE', [$siteId]);
    }

    /** @return array{0: ?int, 1: ?string} new goal id, or an error */
    public function addGoal(int $siteId, string $kind, string $value, ?int $now = null): array
    {
        $value = trim($value);
        if ($kind === 'pageview') {
            $value = '/' . ltrim($value, '/');
            if (!preg_match('#^/[^\s?\#]*$#', $value) || strlen($value) > 200) {
                return [null, 'Enter a path like /thanks or /blog/*, without the domain.'];
            }
        } elseif ($kind === 'event') {
            if ($value === '' || mb_strlen($value) > 60) {
                return [null, 'Enter the event name, for example Signup.'];
            }
        } else {
            return [null, 'Choose a goal type.'];
        }
        if ($this->db->value('SELECT id FROM wyn_goals WHERE site_id = ? AND kind = ? AND value = ?', [$siteId, $kind, $value]) !== null) {
            return [null, 'That goal already exists.'];
        }

        return [$this->db->insert('wyn_goals', ['site_id' => $siteId, 'kind' => $kind, 'value' => $value, 'created_at' => $now ?? time()]), null];
    }

    public static function goalLabel(array $goal): string
    {
        return $goal['kind'] === 'pageview' ? 'Visited ' . $goal['value'] : $goal['value'];
    }

    /** SQL condition for "this event completes the goal", on wyn_events aliased $e. */
    public static function goalCondition(array $goal, string $e = 'e'): array
    {
        if ($goal['kind'] === 'event') {
            return ["({$e}.type = 'event' AND {$e}.name = ?)", [$goal['value']]];
        }
        if (str_contains($goal['value'], '*')) {
            // * matches any characters. Other GLOB characters are escaped with [ ].
            return ["({$e}.type = 'pageview' AND {$e}.path GLOB ?)", [self::glob($goal['value'])]];
        }

        return ["({$e}.type = 'pageview' AND {$e}.path = ?)", [$goal['value']]];
    }

    /** /blog/* → GLOB pattern with only * as a wildcard. */
    public static function glob(string $pattern): string
    {
        return strtr($pattern, ['[' => '[[]', '?' => '[?]']);
    }

    // ------------------------------------------------------------------ Domains

    public static function normaliseDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('#^[a-z]+://#', '', $domain);
        $domain = explode('/', $domain)[0];
        $domain = explode(':', $domain)[0];
        $domain = rtrim($domain, '.');
        if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7F]/', $domain)) {
            $domain = (string) idn_to_ascii($domain);
        }

        return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
    }

    public static function validDomain(string $domain): bool
    {
        return strlen($domain) <= 253
            && (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$|^localhost$/', $domain);
    }

    /** Does a page's host belong to this site? */
    public static function hostMatches(array $site, string $host): bool
    {
        $host = strtolower($host);
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        if ($host === $site['domain']) {
            return true;
        }

        return (bool) $site['include_subdomains'] && str_ends_with($host, '.' . $site['domain']);
    }
}
