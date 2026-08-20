<?php

declare(strict_types=1);

namespace FileBridge\Security;

/**
 * Trust-on-first-use store for SSH host fingerprints. A changed fingerprint
 * aborts the connection instead of silently trusting it.
 *
 * Entries are keyed by host, port and hash algorithm, because the two SSH
 * backends report different digests (ext-ssh2 gives SHA1, phpseclib SHA256).
 * Pinning them separately keeps both honest instead of letting one overwrite
 * the other every time the backend changes.
 */
final class HostKeyStore
{
    public function __construct(private readonly string $file)
    {
    }

    public function known(string $host, int $port, string $algo): ?string
    {
        return $this->all()[$this->key($host, $port, $algo)] ?? null;
    }

    /** @return array{status:'new'|'match'|'changed', expected:?string} */
    public function verify(string $host, int $port, string $fingerprint): array
    {
        $algo  = $this->algo($fingerprint);
        $known = $this->known($host, $port, $algo);

        if ($known === null) {
            $this->remember($host, $port, $fingerprint);

            return ['status' => 'new', 'expected' => null];
        }
        if (hash_equals($known, $fingerprint)) {
            return ['status' => 'match', 'expected' => $known];
        }

        return ['status' => 'changed', 'expected' => $known];
    }

    public function remember(string $host, int $port, string $fingerprint): void
    {
        $all = $this->all();
        $all[$this->key($host, $port, $this->algo($fingerprint))] = $fingerprint;
        $this->write($all);
    }

    /** Drop every pinned digest for a host so the next connection re-pins. */
    public function forget(string $host, int $port): void
    {
        $prefix = strtolower($host) . ':' . $port . ':';
        $all    = array_filter(
            $this->all(),
            static fn (string $key): bool => !str_starts_with($key, $prefix),
            ARRAY_FILTER_USE_KEY
        );
        $this->write($all);
    }

    /** @return array<string,string> */
    public function all(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        return json_decode((string) file_get_contents($this->file), true) ?: [];
    }

    private function write(array $all): void
    {
        @file_put_contents($this->file, json_encode($all, JSON_PRETTY_PRINT), LOCK_EX);
        @chmod($this->file, 0600);
    }

    private function algo(string $fingerprint): string
    {
        $prefix = strtok($fingerprint, ':');

        return strtoupper($prefix === false ? 'UNKNOWN' : $prefix);
    }

    private function key(string $host, int $port, string $algo): string
    {
        return strtolower($host) . ':' . $port . ':' . $algo;
    }
}
