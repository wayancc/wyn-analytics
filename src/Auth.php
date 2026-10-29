<?php

declare(strict_types=1);

namespace WynAnalytics;

use WynAnalytics\Http\Session;

/**
 * Single admin login. Brute force is limited per visitor; the key passed in is a salted hash, never a raw IP.
 */
final class Auth
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW = 900; // 15 minutes

    public function __construct(private readonly Database $db, private readonly Session $session)
    {
    }

    public function hasUsers(): bool
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM wyn_users') > 0;
    }

    public function user(): ?array
    {
        $id = $this->session->get('user_id');

        return $id ? $this->db->one('SELECT id, email FROM wyn_users WHERE id = ?', [$id]) : null;
    }

    public function createUser(string $email, string $password): int
    {
        return $this->db->insert('wyn_users', [
            'email'         => strtolower($email),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_at'    => time(),
        ]);
    }

    public function changePassword(int $userId, string $password): void
    {
        $this->db->update('wyn_users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = :id', ['id' => $userId]);
    }

    public function verifyPassword(int $userId, string $password): bool
    {
        $hash = (string) $this->db->value('SELECT password_hash FROM wyn_users WHERE id = ?', [$userId]);

        return $hash !== '' && password_verify($password, $hash);
    }

    public function isThrottled(string $who): bool
    {
        $this->db->query('DELETE FROM wyn_login_attempts WHERE attempted_at < ?', [time() - self::WINDOW]);

        return (int) $this->db->value('SELECT COUNT(*) FROM wyn_login_attempts WHERE who = ?', [$who]) >= self::MAX_ATTEMPTS;
    }

    public function attempt(string $email, string $password, string $who): bool
    {
        $user = $this->db->one('SELECT id, password_hash FROM wyn_users WHERE email = ?', [strtolower(trim($email))]);

        // Verify against a dummy hash when the user doesn't exist, so timing doesn't reveal valid emails.
        $hash = $user['password_hash'] ?? password_hash(random_bytes(12), PASSWORD_DEFAULT);
        if (!password_verify($password, $hash) || $user === null) {
            $this->db->insert('wyn_login_attempts', ['who' => $who, 'attempted_at' => time()]);

            return false;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->changePassword((int) $user['id'], $password);
        }
        $this->db->query('DELETE FROM wyn_login_attempts WHERE who = ?', [$who]);
        $this->session->regenerate();
        $this->session->set('user_id', (int) $user['id']);

        return true;
    }

    public function login(int $userId): void
    {
        $this->session->regenerate();
        $this->session->set('user_id', $userId);
    }

    public function logout(): void
    {
        $this->session->destroy();
    }
}
