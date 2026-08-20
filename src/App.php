<?php

declare(strict_types=1);

namespace FileBridge;

use FileBridge\Security\Auth;
use FileBridge\Security\Crypto;
use FileBridge\Security\HostKeyStore;
use FileBridge\Security\RateLimit;
use FileBridge\Site\SiteRepository;
use FileBridge\Support\Logger;
use FileBridge\Transfer\JobStore;
use FileBridge\Transfer\TransferManager;

/** Tiny service container - built once per request. */
final class App
{
    private static ?self $instance = null;

    public readonly string $base;
    public readonly array $config;

    private ?Crypto $crypto = null;
    private ?Auth $auth = null;
    private ?SiteRepository $sites = null;
    private ?JobStore $jobs = null;
    private ?Logger $logger = null;

    private function __construct(string $base)
    {
        $this->base = $base;

        $config = require $base . '/config/config.php';
        $local  = $base . '/config/config.local.php';
        if (is_file($local)) {
            // Machine specific settings live here and stay out of git.
            $config = array_merge($config, (array) require $local);
        }
        $this->config = $config;
        $this->ensureStorage();
    }

    public static function boot(?string $base = null): self
    {
        if (self::$instance === null) {
            $base = $base ?? dirname(__DIR__);
            require_once $base . '/vendor/autoload.php';
            self::$instance = new self($base);
        }

        return self::$instance;
    }

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $this->cookiePath(),
            'httponly' => true,
            'samesite' => 'Strict',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_name('filebridge');
        session_save_path($this->base . '/storage/sessions');
        session_start();

        $lifetime = (int) $this->config['session_lifetime'];
        if (isset($_SESSION['login_at']) && time() - (int) $_SESSION['login_at'] > $lifetime) {
            $this->auth()->logout();
            session_start();
        }
    }

    public function crypto(): Crypto
    {
        return $this->crypto ??= new Crypto($this->keyFile());
    }

    /**
     * Where the master key lives. FILEBRIDGE_KEY_FILE moves it off the app
     * directory, which matters when that directory sits on a filesystem that
     * cannot enforce permissions (NTFS/exFAT mounts force 0777).
     */
    public function keyFile(): string
    {
        $override = getenv('FILEBRIDGE_KEY_FILE');
        if (is_string($override) && $override !== '') {
            return $override;
        }
        $configured = (string) ($this->config['key_file'] ?? '');

        return $configured !== '' ? $configured : $this->base . '/config/key.php';
    }

    public function auth(): Auth
    {
        return $this->auth ??= new Auth($this->base . '/config/users.php');
    }

    public function sites(): SiteRepository
    {
        return $this->sites ??= new SiteRepository($this->base . '/config/sites.json', $this->crypto());
    }

    public function jobs(): JobStore
    {
        return $this->jobs ??= new JobStore($this->base . '/storage/jobs', (int) $this->config['job_retention']);
    }

    public function transfers(): TransferManager
    {
        return new TransferManager($this);
    }

    public function logger(): Logger
    {
        return $this->logger ??= new Logger($this->base . '/storage/logs');
    }

    public function hostKeys(): HostKeyStore
    {
        return new HostKeyStore($this->base . '/storage/known_hosts.json');
    }

    public function rateLimit(): RateLimit
    {
        return new RateLimit(
            $this->base . '/storage/tmp',
            (int) $this->config['login_max_attempts'],
            (int) $this->config['login_lockout']
        );
    }

    /**
     * Locate a PHP CLI binary for the background worker.
     *
     * PHP_BINARY is php-fpm under the web SAPI, so it cannot be used directly.
     * The version-suffixed name is tried first to avoid a machine's "php"
     * alternative pointing at a different (possibly older) build.
     */
    public function phpBinary(): ?string
    {
        $configured = (string) ($this->config['php_binary'] ?? '');
        if ($configured !== '' && is_executable($configured)) {
            return $configured;
        }

        $candidates = [];
        if (PHP_SAPI === 'cli' && !str_contains(basename(PHP_BINARY), 'fpm')) {
            $candidates[] = PHP_BINARY;
        }
        $versioned = 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        foreach ([PHP_BINDIR, '/usr/bin', '/usr/local/bin'] as $dir) {
            $candidates[] = $dir . '/' . $versioned;
            $candidates[] = $dir . '/php';
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Client IP is allowed to reach the app at all. */
    public function ipAllowed(): bool
    {
        $list = $this->config['ip_allowlist'] ?? [];
        if ($list === []) {
            return true;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        foreach ($list as $rule) {
            if ($this->ipMatches($ip, (string) $rule)) {
                return true;
            }
        }

        return false;
    }

    private function ipMatches(string $ip, string $rule): bool
    {
        if (!str_contains($rule, '/')) {
            return $ip === $rule;
        }
        [$subnet, $bits] = explode('/', $rule, 2);
        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $mask = -1 << (32 - (int) $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private function cookiePath(): string
    {
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));

        return rtrim($dir, '/') . '/';
    }

    private function ensureStorage(): void
    {
        foreach (['sessions', 'jobs', 'logs', 'tmp'] as $dir) {
            $path = $this->base . '/storage/' . $dir;
            if (!is_dir($path)) {
                @mkdir($path, 0700, true);
            }
        }
    }
}
