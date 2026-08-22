<?php

declare(strict_types=1);

namespace FileBridge\Fs;

use FileBridge\Security\HostKeyStore;
use FileBridge\Support\Lang;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SFTP\Stream as SftpStream;
use Throwable;

/**
 * SFTP over phpseclib - pure PHP, works without any extension.
 * Reads and writes stream through phpseclib's own wrapper, so a transfer of any
 * size runs at constant memory.
 */
final class SftpSeclibDriver implements DriverInterface, ExecCapable
{
    private const TYPE_REGULAR   = 1;
    private const TYPE_DIRECTORY = 2;
    private const TYPE_SYMLINK   = 3;

    private ?SFTP $sftp = null;
    private string $home = '/';
    private static bool $wrapperRegistered = false;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 22,
        private readonly string $username = 'root',
        private readonly string $password = '',
        private readonly string $privateKey = '',
        private readonly string $passphrase = '',
        private readonly int $timeout = 20,
        private readonly string $startPath = '',
        private readonly ?HostKeyStore $hostKeys = null,
        private readonly bool $verifyHostKey = true
    ) {
    }

    public function connect(): void
    {
        if ($this->sftp !== null) {
            return;
        }
        try {
            $sftp = new SFTP($this->host, $this->port, $this->timeout);
            $sftp->disableStatCache();
        } catch (Throwable $e) {
            throw new ConnectionException(Lang::t('fs.unreachable', [
                'host' => $this->host, 'port' => $this->port, 'error' => $e->getMessage(),
            ]));
        }

        $this->checkHostKey($sftp);

        $credential = $this->credential();
        try {
            $ok = $credential === null
                ? $sftp->login($this->username)
                : $sftp->login($this->username, $credential);
        } catch (Throwable $e) {
            throw new ConnectionException(Lang::t('fs.ssh_auth_error', ['error' => $e->getMessage()]));
        }
        if (!$ok) {
            throw new ConnectionException(Lang::t('fs.ssh_auth', ['user' => $this->username]));
        }

        $this->sftp = $sftp;
        $pwd        = $sftp->pwd();
        $this->home = $this->startPath !== ''
            ? Path::normalise($this->startPath)
            : Path::normalise($pwd === false ? '/' : $pwd);
    }

    public function disconnect(): void
    {
        $this->sftp?->disconnect();
        $this->sftp = null;
    }

    public function home(): string
    {
        return $this->home;
    }

    public function list(string $path): array
    {
        $sftp = $this->handle();
        $path = Path::normalise($path);
        $rows = $sftp->rawlist($path);
        if ($rows === false) {
            throw new FsException(Lang::t('fs.list', ['path' => $path]));
        }

        $entries = [];
        foreach ($rows as $name => $attr) {
            $name = (string) $name;
            if ($name === '.' || $name === '..' || !is_array($attr)) {
                continue;
            }
            $type = (int) ($attr['type'] ?? self::TYPE_REGULAR);
            $full = Path::join($path, $name);

            $targetIsDir = false;
            $target      = null;
            if ($type === self::TYPE_SYMLINK) {
                $resolved    = $sftp->stat($full);
                $targetIsDir = is_array($resolved) && (int) ($resolved['type'] ?? 0) === self::TYPE_DIRECTORY;
                $target      = $sftp->readlink($full) ?: null;
            }

            $entries[] = new Entry(
                name: $name,
                path: $full,
                type: match ($type) {
                    self::TYPE_DIRECTORY => 'dir',
                    self::TYPE_SYMLINK   => 'link',
                    default              => 'file',
                },
                size: (int) ($attr['size'] ?? 0),
                mtime: (int) ($attr['mtime'] ?? 0),
                perms: isset($attr['mode']) ? ((int) $attr['mode'] & 0777) : null,
                owner: (string) ($attr['uid'] ?? ''),
                group: (string) ($attr['gid'] ?? ''),
                target: $target,
                targetIsDir: $targetIsDir
            );
        }

        return $entries;
    }

    public function stat(string $path): ?Entry
    {
        $sftp = $this->handle();
        $path = Path::normalise($path);
        $attr = $sftp->stat($path);
        if (!is_array($attr)) {
            return null;
        }
        $type = (int) ($attr['type'] ?? self::TYPE_REGULAR);

        return new Entry(
            name: Path::name($path),
            path: $path,
            type: $type === self::TYPE_DIRECTORY ? 'dir' : ($type === self::TYPE_SYMLINK ? 'link' : 'file'),
            size: (int) ($attr['size'] ?? 0),
            mtime: (int) ($attr['mtime'] ?? 0),
            perms: isset($attr['mode']) ? ((int) $attr['mode'] & 0777) : null,
            owner: (string) ($attr['uid'] ?? ''),
            group: (string) ($attr['gid'] ?? '')
        );
    }

    public function exists(string $path): bool
    {
        return $this->handle()->file_exists(Path::normalise($path));
    }

    public function mkdir(string $path): void
    {
        $sftp = $this->handle();
        $path = Path::normalise($path);
        // An existing directory is success, not a failure - a transfer often
        // writes into folders that are already there.
        if ($sftp->is_dir($path)) {
            return;
        }
        if (!$sftp->mkdir($path, -1, true)) {
            $sftp->clearStatCache();
            if (!$sftp->is_dir($path)) {
                throw new FsException(Lang::t('fs.mkdir', ['path' => $path]));
            }
        }
    }

    public function delete(string $path, bool $recursive = false): void
    {
        if (!$this->handle()->delete(Path::normalise($path), $recursive)) {
            throw new FsException(Lang::t('fs.delete', ['path' => $path]));
        }
    }

    public function rename(string $from, string $to): void
    {
        if (!$this->handle()->rename(Path::normalise($from), Path::normalise($to))) {
            throw new FsException(Lang::t('fs.rename', ['path' => $from]));
        }
    }

    public function chmod(string $path, int $mode): void
    {
        if ($this->handle()->chmod($mode, Path::normalise($path)) === false) {
            throw new FsException(Lang::t('fs.chmod', ['path' => $path]));
        }
    }

    public function readStream(string $path)
    {
        $sftp   = $this->handle();
        $handle = @fopen($this->url($path), 'rb', false, $this->context($sftp));
        if (!is_resource($handle)) {
            throw new FsException(Lang::t('fs.read', ['path' => $path]));
        }
        Stream::prepare($handle);

        return $handle;
    }

    public function writeStream(string $path, $handle, int $size = 0, int $offset = 0): void
    {
        $sftp = $this->handle();
        $path = Path::normalise($path);
        $ok   = $offset > 0
            ? $sftp->put($path, $handle, SFTP::SOURCE_STRING, $offset)
            : $sftp->put($path, $handle);
        if ($ok === false) {
            throw new FsException(Lang::t('fs.upload_reason', ['path' => $path, 'error' => $this->lastError($sftp)]));
        }
    }

    public function realpath(string $path): string
    {
        $sftp = $this->handle();
        $path = $path === '' ? $this->home : Path::normalise($path);
        $real = $sftp->realpath($path);

        return Path::normalise($real === false ? $path : (string) $real);
    }

    public function exec(string $command): string
    {
        $out = $this->handle()->exec($command);

        return is_string($out) ? $out : '';
    }

    public function capabilities(): array
    {
        return ['chmod' => true, 'resume' => true, 'exec' => true, 'owner' => true, 'symlink' => true];
    }

    public function describe(): string
    {
        return 'SFTP - ' . $this->serverId() . ' (' . $this->backend() . ')';
    }

    public function backend(): string
    {
        return 'phpseclib';
    }

    /** The server's identification banner, or its host name when it offered none. */
    public function serverId(): string
    {
        $id = $this->sftp?->getServerIdentification();

        return $id !== false && $id !== null ? $id : $this->host;
    }

    /** Host identity as "SHA256:base64", matching ssh-keygen -l output. */
    public function fingerprint(): string
    {
        $key = $this->sftp?->getServerPublicHostKey();
        if (!is_string($key) || $key === '') {
            return '';
        }
        $parts = explode(' ', $key);
        $blob  = base64_decode($parts[1] ?? '', true);
        if ($blob === false) {
            return '';
        }

        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    private function checkHostKey(SFTP $sftp): void
    {
        if (!$this->verifyHostKey || $this->hostKeys === null) {
            return;
        }
        $key = $sftp->getServerPublicHostKey();
        if (!is_string($key) || $key === '') {
            return;
        }
        $parts = explode(' ', $key);
        $blob  = base64_decode($parts[1] ?? '', true);
        if ($blob === false) {
            return;
        }
        $fp     = 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
        $result = $this->hostKeys->verify($this->host, $this->port, $fp);

        if ($result['status'] === 'changed') {
            throw new ConnectionException(Lang::t('fs.hostkey_changed', [
                'host'     => $this->host,
                'port'     => $this->port,
                'expected' => (string) $result['expected'],
                'got'      => $fp,
            ]));
        }
    }

    private function credential(): string|object|null
    {
        if ($this->privateKey !== '') {
            try {
                $key = PublicKeyLoader::load($this->privateKey, $this->passphrase !== '' ? $this->passphrase : false);
            } catch (Throwable $e) {
                throw new ConnectionException(Lang::t('fs.key_load', ['error' => $e->getMessage()]));
            }
            if (!$key instanceof PrivateKey) {
                throw new ConnectionException(Lang::t('fs.key_public'));
            }

            return $key;
        }

        return $this->password !== '' ? $this->password : null;
    }

    private function url(string $path): string
    {
        return 'sftp://fb' . Path::normalise($path);
    }

    /** @return resource */
    private function context(SFTP $sftp)
    {
        if (!self::$wrapperRegistered) {
            if (!in_array('sftp', stream_get_wrappers(), true)) {
                SftpStream::register('sftp');
            }
            self::$wrapperRegistered = true;
        }

        return stream_context_create(['sftp' => ['session' => $sftp]]);
    }

    private function lastError(SFTP $sftp): string
    {
        $errors = $sftp->getSFTPErrors();

        return $errors === [] ? 'unknown error' : (string) end($errors);
    }

    private function handle(): SFTP
    {
        $this->connect();
        if ($this->sftp === null) {
            throw new ConnectionException(Lang::t('fs.sftp_closed'));
        }

        return $this->sftp;
    }
}
