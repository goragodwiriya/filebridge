<?php

declare(strict_types=1);

namespace FileBridge\Security;

use RuntimeException;

/**
 * Single-file user store. The first run has no users, which puts the UI into
 * setup mode so the operator can create the administrator account.
 */
final class Auth
{
    public function __construct(private readonly string $usersFile)
    {
    }

    public function needsSetup(): bool
    {
        return $this->users() === [];
    }

    public function createAdmin(string $username, string $password): void
    {
        if (!$this->needsSetup()) {
            throw new RuntimeException('Setup has already been completed.');
        }
        $username = trim($username);
        if (strlen($username) < 3) {
            throw new RuntimeException('Username must be at least 3 characters.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Password must be at least 8 characters.');
        }
        $this->save([[
            'username' => $username,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role'     => 'admin',
            'created'  => date('c'),
        ]]);
    }

    public function attempt(string $username, string $password): bool
    {
        foreach ($this->users() as $user) {
            if (hash_equals((string) $user['username'], $username)
                && password_verify($password, (string) $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user'] = ['username' => $user['username'], 'role' => $user['role'] ?? 'admin'];
                $_SESSION['login_at'] = time();

                return true;
            }
        }
        // Constant-ish work even when the user does not exist.
        password_verify($password, '$2y$12$usesomesillystringfoxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');

        return false;
    }

    public function changePassword(string $username, string $current, string $new): void
    {
        if (strlen($new) < 8) {
            throw new RuntimeException('New password must be at least 8 characters.');
        }
        $users = $this->users();
        foreach ($users as $i => $user) {
            if ($user['username'] === $username) {
                if (!password_verify($current, (string) $user['password'])) {
                    throw new RuntimeException('Current password is incorrect.');
                }
                $users[$i]['password'] = password_hash($new, PASSWORD_DEFAULT);
                $this->save($users);

                return;
            }
        }
        throw new RuntimeException('User not found.');
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public function check(): bool
    {
        return isset($_SESSION['user']['username']);
    }

    public function username(): string
    {
        return (string) ($_SESSION['user']['username'] ?? '');
    }

    private function users(): array
    {
        if (!is_file($this->usersFile)) {
            return [];
        }
        $users = require $this->usersFile;

        return is_array($users) ? $users : [];
    }

    private function save(array $users): void
    {
        $code = "<?php\n// FileBridge users - generated file, do not expose to the web.\nreturn "
            . var_export($users, true) . ";\n";
        if (@file_put_contents($this->usersFile, $code, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write ' . $this->usersFile);
        }
        @chmod($this->usersFile, 0600);
    }
}
