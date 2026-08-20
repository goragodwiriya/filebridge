<?php

declare(strict_types=1);

namespace FileBridge\Site;

/** A saved connection profile. Secrets here are always plaintext in memory. */
final class Site
{
    public const LOCAL_ID = 'local';

    public function __construct(
        public string $id = '',
        public string $name = '',
        public string $protocol = 'sftp',    // sftp | ftp | ftps | local
        public string $host = '',
        public int $port = 22,
        public string $username = '',
        public string $auth = 'password',    // password | key
        public string $password = '',
        public string $privateKey = '',
        public string $passphrase = '',
        public string $rootPath = '',
        public bool $passive = true,
        public int $timeout = 20,
        public string $colour = '#6366f1',
        public string $backend = 'auto',     // auto | ssh2 | phpseclib
        public string $created = '',
        public string $updated = ''
    ) {
    }

    public static function local(): self
    {
        return new self(
            id: self::LOCAL_ID,
            name: 'This server',
            protocol: 'local',
            host: 'localhost',
            port: 0,
            colour: '#22c55e'
        );
    }

    public static function defaultPort(string $protocol): int
    {
        return match ($protocol) {
            'ftp', 'ftps' => 21,
            'local'       => 0,
            default       => 22,
        };
    }

    public static function fromArray(array $data): self
    {
        $protocol = in_array($data['protocol'] ?? '', ['sftp', 'ftp', 'ftps', 'local'], true)
            ? (string) $data['protocol']
            : 'sftp';

        return new self(
            id: (string) ($data['id'] ?? ''),
            name: trim((string) ($data['name'] ?? '')),
            protocol: $protocol,
            host: trim((string) ($data['host'] ?? '')),
            port: (int) ($data['port'] ?? 0) ?: self::defaultPort($protocol),
            username: trim((string) ($data['username'] ?? '')),
            auth: ($data['auth'] ?? 'password') === 'key' ? 'key' : 'password',
            password: (string) ($data['password'] ?? ''),
            privateKey: (string) ($data['privateKey'] ?? ''),
            passphrase: (string) ($data['passphrase'] ?? ''),
            rootPath: trim((string) ($data['rootPath'] ?? '')),
            passive: (bool) ($data['passive'] ?? true),
            timeout: max(5, (int) ($data['timeout'] ?? 20)),
            colour: preg_match('/^#[0-9a-f]{6}$/i', (string) ($data['colour'] ?? '')) ? (string) $data['colour'] : '#6366f1',
            backend: in_array($data['backend'] ?? '', ['auto', 'ssh2', 'phpseclib'], true) ? (string) $data['backend'] : 'auto',
            created: (string) ($data['created'] ?? ''),
            updated: (string) ($data['updated'] ?? '')
        );
    }

    /** Full record for storage (secrets still plaintext - the repository encrypts). */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'protocol'   => $this->protocol,
            'host'       => $this->host,
            'port'       => $this->port,
            'username'   => $this->username,
            'auth'       => $this->auth,
            'password'   => $this->password,
            'privateKey' => $this->privateKey,
            'passphrase' => $this->passphrase,
            'rootPath'   => $this->rootPath,
            'passive'    => $this->passive,
            'timeout'    => $this->timeout,
            'colour'     => $this->colour,
            'backend'    => $this->backend,
            'created'    => $this->created,
            'updated'    => $this->updated,
        ];
    }

    /** Safe projection for the browser - never carries a secret. */
    public function toClient(): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'protocol'    => $this->protocol,
            'host'        => $this->host,
            'port'        => $this->port,
            'username'    => $this->username,
            'auth'        => $this->auth,
            'hasPassword' => $this->password !== '',
            'hasKey'      => $this->privateKey !== '',
            'rootPath'    => $this->rootPath,
            'passive'     => $this->passive,
            'timeout'     => $this->timeout,
            'colour'      => $this->colour,
            'backend'     => $this->backend,
            'label'       => $this->protocol === 'local'
                ? 'local'
                : strtoupper($this->protocol) . ' · ' . $this->username . '@' . $this->host,
        ];
    }
}
