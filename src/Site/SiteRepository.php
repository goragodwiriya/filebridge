<?php

declare(strict_types=1);

namespace FileBridge\Site;

use FileBridge\Security\Crypto;
use RuntimeException;

/** sites.json persistence with the three secret fields encrypted at rest. */
final class SiteRepository
{
    private const SECRETS = ['password', 'privateKey', 'passphrase'];

    public function __construct(
        private readonly string $file,
        private readonly Crypto $crypto
    ) {
    }

    /** @return Site[] keyed by id, always including the built-in local site */
    public function all(): array
    {
        $sites = [Site::LOCAL_ID => Site::local()];
        foreach ($this->read() as $row) {
            foreach (self::SECRETS as $field) {
                $row[$field] = $this->crypto->decrypt((string) ($row[$field] ?? ''));
            }
            $site = Site::fromArray($row);
            if ($site->id !== '') {
                $sites[$site->id] = $site;
            }
        }

        return $sites;
    }

    public function find(string $id): ?Site
    {
        return $this->all()[$id] ?? null;
    }

    public function findOrFail(string $id): Site
    {
        return $this->find($id) ?? throw new RuntimeException('Unknown connection: ' . $id);
    }

    public function save(array $input): Site
    {
        $site = Site::fromArray($input);
        if ($site->id === Site::LOCAL_ID) {
            throw new RuntimeException('The local connection cannot be edited.');
        }
        if ($site->name === '') {
            throw new RuntimeException('Give the connection a name.');
        }
        if ($site->protocol !== 'local' && $site->host === '') {
            throw new RuntimeException('Host is required.');
        }

        $rows     = $this->read();
        $now      = date('c');
        $existing = null;
        foreach ($rows as $i => $row) {
            if (($row['id'] ?? '') === $site->id && $site->id !== '') {
                $existing = $i;
                break;
            }
        }

        if ($existing === null) {
            $site->id      = bin2hex(random_bytes(8));
            $site->created = $now;
        } else {
            // Empty secret means "keep the stored one" - the UI never sends it back.
            foreach (self::SECRETS as $field) {
                if ($site->{$field} === '') {
                    $site->{$field} = $this->crypto->decrypt((string) ($rows[$existing][$field] ?? ''));
                }
            }
            $site->created = (string) ($rows[$existing]['created'] ?? $now);
        }
        $site->updated = $now;

        $record = $site->toArray();
        foreach (self::SECRETS as $field) {
            $record[$field] = $this->crypto->encrypt((string) $record[$field]);
        }

        if ($existing === null) {
            $rows[] = $record;
        } else {
            $rows[$existing] = $record;
        }
        $this->write(array_values($rows));

        return $site;
    }

    public function delete(string $id): void
    {
        if ($id === Site::LOCAL_ID) {
            throw new RuntimeException('The local connection cannot be removed.');
        }
        $rows = array_values(array_filter(
            $this->read(),
            static fn (array $row): bool => ($row['id'] ?? '') !== $id
        ));
        $this->write($rows);
    }

    private function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->file), true);

        return is_array($data) ? $data : [];
    }

    private function write(array $rows): void
    {
        $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($this->file, $json, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write ' . $this->file . ' - check directory permissions.');
        }
        @chmod($this->file, 0600);
    }
}
