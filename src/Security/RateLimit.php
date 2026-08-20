<?php

declare(strict_types=1);

namespace FileBridge\Security;

/** Simple file-backed attempt counter, keyed by action + client IP. */
final class RateLimit
{
    public function __construct(
        private readonly string $dir,
        private readonly int $maxAttempts,
        private readonly int $lockoutSeconds
    ) {
    }

    public function tooManyAttempts(string $key): bool
    {
        return $this->remaining($key) <= 0;
    }

    public function remaining(string $key): int
    {
        $state = $this->read($key);

        return max(0, $this->maxAttempts - $state['count']);
    }

    /** Seconds left before the lock expires (0 when not locked). */
    public function retryAfter(string $key): int
    {
        $state = $this->read($key);
        if ($state['count'] < $this->maxAttempts) {
            return 0;
        }

        return max(0, $state['expires'] - time());
    }

    public function hit(string $key): void
    {
        $state = $this->read($key);
        $state['count']++;
        $state['expires'] = time() + $this->lockoutSeconds;
        @file_put_contents($this->file($key), json_encode($state), LOCK_EX);
    }

    public function clear(string $key): void
    {
        @unlink($this->file($key));
    }

    private function read(string $key): array
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return ['count' => 0, 'expires' => 0];
        }
        $state = json_decode((string) file_get_contents($file), true);
        if (!is_array($state) || ($state['expires'] ?? 0) < time()) {
            return ['count' => 0, 'expires' => 0];
        }

        return ['count' => (int) $state['count'], 'expires' => (int) $state['expires']];
    }

    private function file(string $key): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';

        return $this->dir . '/rl-' . sha1($key . '|' . $ip) . '.json';
    }
}
