<?php

declare(strict_types=1);

namespace FileBridge\Fs;

use FileBridge\Security\HostKeyStore;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use Throwable;

/**
 * SFTP through ext-ssh2 (libssh2). Roughly 2-3x faster than the pure PHP path,
 * used automatically when the extension is present. DriverFactory falls back to
 * SftpSeclibDriver if this backend cannot connect.
 */
final class SftpSsh2Driver implements DriverInterface, ExecCapable
{
    private mixed $conn = null;
    private mixed $sftp = null;
    private string $home = '/';
    private string $banner = '';
    /** @var string[] */
    private array $tempKeys = [];

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

    public static function available(): bool
    {
        return extension_loaded('ssh2');
    }

    public function connect(): void
    {
        if ($this->sftp !== null) {
            return;
        }
        // ssh2_connect() takes no timeout and blocks on the OS default, which can
        // be minutes on a filtered port. Probing the socket first keeps the
        // configured timeout meaningful and turns a hang into a clear error.
        $probe = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if ($probe === false) {
            throw new ConnectionException(sprintf(
                'Cannot reach %s:%d - %s',
                $this->host,
                $this->port,
                $errstr !== '' ? $errstr : 'no response within ' . $this->timeout . 's'
            ));
        }
        fclose($probe);

        $conn = @ssh2_connect($this->host, $this->port);
        if ($conn === false) {
            throw new ConnectionException('SSH handshake failed with ' . $this->host . ':' . $this->port);
        }
        $this->conn = $conn;
        $this->checkHostKey();

        if (!$this->authenticate()) {
            throw new ConnectionException('SSH authentication failed for user "' . $this->username . '".');
        }

        $sftp = @ssh2_sftp($conn);
        if ($sftp === false) {
            throw new ConnectionException('The server did not start an SFTP subsystem.');
        }
        $this->sftp   = $sftp;
        $this->banner = 'SFTP - ' . $this->host . ' (ext-ssh2)';
        $home         = @ssh2_sftp_realpath($sftp, '.');
        $this->home   = $this->startPath !== ''
            ? Path::normalise($this->startPath)
            : Path::normalise($home === false ? '/' : (string) $home);
    }

    public function disconnect(): void
    {
        $this->wipeKeyFiles();
        if ($this->conn !== null && function_exists('ssh2_disconnect')) {
            @ssh2_disconnect($this->conn);
        }
        $this->sftp = null;
        $this->conn = null;
    }

    public function home(): string
    {
        return $this->home;
    }

    public function list(string $path): array
    {
        $this->connect();
        $path   = Path::normalise($path);
        $handle = @opendir($this->wrap($path));
        if ($handle === false) {
            throw new FsException('Cannot list directory: ' . $path);
        }

        $entries = [];
        while (($name = readdir($handle)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full  = Path::join($path, $name);
            $lstat = @ssh2_sftp_lstat($this->sftp, $full) ?: [];
            $mode  = (int) ($lstat['mode'] ?? 0);
            $isLink = ($mode & 0170000) === 0120000;
            $stat  = $isLink ? (@ssh2_sftp_stat($this->sftp, $full) ?: $lstat) : $lstat;
            $isDir = ((int) ($stat['mode'] ?? 0) & 0170000) === 0040000;

            $entries[] = new Entry(
                name: $name,
                path: $full,
                type: $isLink ? 'link' : ($isDir ? 'dir' : 'file'),
                size: (int) ($stat['size'] ?? 0),
                mtime: (int) ($stat['mtime'] ?? 0),
                perms: isset($stat['mode']) ? ((int) $stat['mode'] & 0777) : null,
                owner: (string) ($stat['uid'] ?? ''),
                group: (string) ($stat['gid'] ?? ''),
                target: $isLink ? (@ssh2_sftp_readlink($this->sftp, $full) ?: null) : null,
                targetIsDir: $isLink && $isDir
            );
        }
        closedir($handle);

        return $entries;
    }

    public function stat(string $path): ?Entry
    {
        $this->connect();
        $path = Path::normalise($path);
        $stat = @ssh2_sftp_stat($this->sftp, $path);
        if ($stat === false) {
            return null;
        }
        $isDir = ((int) ($stat['mode'] ?? 0) & 0170000) === 0040000;

        return new Entry(
            name: Path::name($path),
            path: $path,
            type: $isDir ? 'dir' : 'file',
            size: (int) ($stat['size'] ?? 0),
            mtime: (int) ($stat['mtime'] ?? 0),
            perms: isset($stat['mode']) ? ((int) $stat['mode'] & 0777) : null,
            owner: (string) ($stat['uid'] ?? ''),
            group: (string) ($stat['gid'] ?? '')
        );
    }

    public function exists(string $path): bool
    {
        $this->connect();

        return @ssh2_sftp_stat($this->sftp, Path::normalise($path)) !== false;
    }

    public function mkdir(string $path): void
    {
        $this->connect();
        if (!@ssh2_sftp_mkdir($this->sftp, Path::normalise($path), 0755, true)) {
            throw new FsException('Cannot create directory: ' . $path);
        }
    }

    public function delete(string $path, bool $recursive = false): void
    {
        $this->connect();
        $path = Path::normalise($path);
        $stat = @ssh2_sftp_stat($this->sftp, $path);
        $isDir = is_array($stat) && ((int) ($stat['mode'] ?? 0) & 0170000) === 0040000;

        if (!$isDir) {
            if (!@ssh2_sftp_unlink($this->sftp, $path)) {
                throw new FsException('Cannot delete: ' . $path);
            }

            return;
        }
        if ($recursive) {
            foreach ($this->list($path) as $entry) {
                $this->delete($entry->path, $entry->type === 'dir');
            }
        }
        if (!@ssh2_sftp_rmdir($this->sftp, $path)) {
            throw new FsException('Cannot delete directory: ' . $path);
        }
    }

    public function rename(string $from, string $to): void
    {
        $this->connect();
        if (!@ssh2_sftp_rename($this->sftp, Path::normalise($from), Path::normalise($to))) {
            throw new FsException('Rename failed: ' . $from);
        }
    }

    public function chmod(string $path, int $mode): void
    {
        $this->connect();
        if (!@ssh2_sftp_chmod($this->sftp, Path::normalise($path), $mode)) {
            throw new FsException('chmod failed: ' . $path);
        }
    }

    public function readStream(string $path)
    {
        $this->connect();
        $handle = @fopen($this->wrap(Path::normalise($path)), 'rb');
        if (!is_resource($handle)) {
            throw new FsException('Cannot read: ' . $path);
        }

        return $handle;
    }

    public function writeStream(string $path, $handle, int $size = 0, int $offset = 0): void
    {
        $this->connect();
        $url = $this->wrap(Path::normalise($path));
        $out = @fopen($url, $offset > 0 ? 'r+b' : 'wb');
        if (!is_resource($out)) {
            throw new FsException('Cannot write: ' . $path);
        }
        if ($offset > 0) {
            fseek($out, $offset);
        }
        stream_copy_to_stream($handle, $out);
        fclose($out);
    }

    public function realpath(string $path): string
    {
        $this->connect();
        $path = $path === '' ? $this->home : Path::normalise($path);
        $real = @ssh2_sftp_realpath($this->sftp, $path);

        return Path::normalise($real === false ? $path : (string) $real);
    }

    public function exec(string $command): string
    {
        $this->connect();
        $stream = @ssh2_exec($this->conn, $command);
        if ($stream === false) {
            throw new FsException('Remote command failed: ' . $command);
        }
        stream_set_blocking($stream, true);
        $out = (string) stream_get_contents($stream);
        fclose($stream);

        return $out;
    }

    public function capabilities(): array
    {
        return ['chmod' => true, 'resume' => true, 'exec' => true, 'owner' => true, 'symlink' => true];
    }

    public function describe(): string
    {
        return $this->banner !== '' ? $this->banner : 'SFTP - ' . $this->host . ' (ext-ssh2)';
    }

    private function wrap(string $path): string
    {
        return 'ssh2.sftp://' . (int) $this->sftp . $path;
    }

    private function authenticate(): bool
    {
        if ($this->privateKey !== '') {
            [$pub, $priv] = $this->writeKeyPair();

            try {
                // ext-ssh2 types $passphrase as a plain string: null is a TypeError,
                // and an empty string is what "no passphrase" looks like to libssh2.
                return @ssh2_auth_pubkey_file($this->conn, $this->username, $pub, $priv, $this->passphrase);
            } finally {
                // libssh2 has read both files by now - the key material should
                // not survive the call, whatever its outcome was.
                $this->wipeKeyFiles();
            }
        }

        return @ssh2_auth_password($this->conn, $this->username, $this->password);
    }

    /** ext-ssh2 needs both halves on disk; derive the public half from the private key. */
    private function writeKeyPair(): array
    {
        try {
            $key = PublicKeyLoader::load($this->privateKey, $this->passphrase !== '' ? $this->passphrase : false);
        } catch (Throwable $e) {
            throw new ConnectionException('Private key could not be loaded: ' . $e->getMessage());
        }
        if (!$key instanceof PrivateKey) {
            throw new ConnectionException(
                'That key is a public key. Paste the private half (the file without the .pub suffix).'
            );
        }
        $pub = $key->getPublicKey()->toString('OpenSSH');

        // The system temp dir, not the app's storage: storage/ may live on a
        // mount that ignores chmod (NTFS/exFAT via FUSE forces 0777), which
        // would leave the private key world readable.
        $dir      = sys_get_temp_dir();
        $privFile = (string) tempnam($dir, 'fb-key-');
        $pubFile  = $privFile . '.pub';
        $this->tempKeys[] = $privFile;
        $this->tempKeys[] = $pubFile;

        // Narrow the mode before any key material is written.
        @chmod($privFile, 0600);
        @touch($pubFile);
        @chmod($pubFile, 0600);
        file_put_contents($privFile, $this->privateKey);
        file_put_contents($pubFile, $pub);

        return [$pubFile, $privFile];
    }

    /** Remove every temporary key file this driver created. */
    private function wipeKeyFiles(): void
    {
        foreach ($this->tempKeys as $file) {
            if (is_file($file)) {
                @file_put_contents($file, str_repeat("\0", (int) filesize($file) ?: 1));
                @unlink($file);
            }
        }
        $this->tempKeys = [];
    }

    /** Last resort: a key file must never outlive the process that wrote it. */
    public function __destruct()
    {
        $this->wipeKeyFiles();
    }

    private function checkHostKey(): void
    {
        if (!$this->verifyHostKey || $this->hostKeys === null) {
            return;
        }
        $raw = @ssh2_fingerprint($this->conn, SSH2_FINGERPRINT_SHA1 | SSH2_FINGERPRINT_HEX);
        if (!is_string($raw) || $raw === '') {
            return;
        }
        $fp     = 'SHA1:' . strtolower($raw);
        $result = $this->hostKeys->verify($this->host, $this->port, $fp);

        if ($result['status'] === 'changed') {
            throw new ConnectionException(sprintf(
                "HOST KEY CHANGED for %s:%d.\nExpected %s\nGot      %s\n"
                . 'Someone could be intercepting the connection. Clear the pinned key in Settings if this was intended.',
                $this->host,
                $this->port,
                (string) $result['expected'],
                $fp
            ));
        }
    }
}
