<?php

declare(strict_types=1);

namespace FileBridge\Fs;

use FTP\Connection;

/**
 * FTP and explicit FTPS through ext-ftp.
 *
 * Metadata and file operations run over the persistent control connection.
 * Bulk transfers stream: uploads through ftp_fput (source handle straight to
 * the socket) and downloads through the ftp:// wrapper, so neither direction
 * buffers a whole file in memory.
 */
final class FtpDriver implements DriverInterface
{
    private ?Connection $conn = null;
    private string $home = '/';
    private string $banner = '';
    private bool $hasMlsd = true;
    /** @var string[] spool files to clean up on disconnect */
    private array $spool = [];

    public function __construct(
        private readonly string $host,
        private readonly int $port = 21,
        private readonly string $username = 'anonymous',
        private readonly string $password = '',
        private readonly bool $ssl = false,
        private readonly bool $passive = true,
        private readonly int $timeout = 20,
        private readonly string $startPath = '',
        private readonly string $spoolDir = '/tmp'
    ) {
    }

    public function connect(): void
    {
        if ($this->conn !== null) {
            return;
        }
        $conn = $this->ssl
            ? @ftp_ssl_connect($this->host, $this->port, $this->timeout)
            : @ftp_connect($this->host, $this->port, $this->timeout);

        if ($conn === false) {
            throw new ConnectionException(sprintf(
                'Cannot reach %s://%s:%d',
                $this->ssl ? 'ftps' : 'ftp',
                $this->host,
                $this->port
            ));
        }
        if (!@ftp_login($conn, $this->username, $this->password)) {
            @ftp_close($conn);
            throw new ConnectionException('FTP login failed for user "' . $this->username . '".');
        }
        @ftp_set_option($conn, FTP_TIMEOUT_SEC, $this->timeout);
        @ftp_pasv($conn, $this->passive);

        $this->conn   = $conn;
        $this->banner = ($this->ssl ? 'FTPS' : 'FTP') . ' - ' . $this->host;
        $pwd          = @ftp_pwd($conn);
        $this->home   = $this->startPath !== ''
            ? Path::normalise($this->startPath)
            : Path::normalise($pwd === false ? '/' : $pwd);
    }

    public function disconnect(): void
    {
        foreach ($this->spool as $file) {
            @unlink($file);
        }
        $this->spool = [];
        if ($this->conn !== null) {
            @ftp_close($this->conn);
            $this->conn = null;
        }
    }

    public function home(): string
    {
        return $this->home;
    }

    public function list(string $path): array
    {
        $conn = $this->handle();
        $path = Path::normalise($path);

        if ($this->hasMlsd) {
            $rows = @ftp_mlsd($conn, $path);
            if (is_array($rows)) {
                return $this->fromMlsd($rows, $path);
            }
            $this->hasMlsd = false; // server does not speak MLSD, fall back once
        }

        $raw = @ftp_rawlist($conn, '-la ' . $this->quote($path));
        if ($raw === false) {
            $raw = @ftp_rawlist($conn, $path);
        }
        if ($raw === false) {
            throw new FsException('Cannot list directory: ' . $path);
        }

        return $this->fromRawList($raw, $path);
    }

    public function stat(string $path): ?Entry
    {
        $path   = Path::normalise($path);
        $parent = Path::parent($path);
        $name   = Path::name($path);
        foreach ($this->list($parent) as $entry) {
            if ($entry->name === $name) {
                return $entry;
            }
        }

        return null;
    }

    public function exists(string $path): bool
    {
        $conn = $this->handle();
        if (@ftp_size($conn, Path::normalise($path)) >= 0) {
            return true;
        }
        $pwd = @ftp_pwd($conn);
        if (@ftp_chdir($conn, Path::normalise($path))) {
            $pwd !== false && @ftp_chdir($conn, $pwd);

            return true;
        }

        return false;
    }

    public function mkdir(string $path): void
    {
        $conn = $this->handle();
        $path = Path::normalise($path);
        // Build parents one level at a time; servers rarely support recursive MKD.
        $current = '';
        foreach (array_filter(explode('/', $path)) as $segment) {
            $current .= '/' . $segment;
            if (!@ftp_chdir($conn, $current)) {
                if (!@ftp_mkdir($conn, $current)) {
                    throw new FsException('Cannot create directory: ' . $current);
                }
            }
        }
        @ftp_chdir($conn, $this->home);
    }

    public function delete(string $path, bool $recursive = false): void
    {
        $conn = $this->handle();
        $path = Path::normalise($path);

        if (@ftp_delete($conn, $path)) {
            return;
        }
        if (!$recursive) {
            if (!@ftp_rmdir($conn, $path)) {
                throw new FsException('Cannot delete: ' . $path);
            }

            return;
        }
        foreach ($this->list($path) as $entry) {
            $this->delete($entry->path, $entry->type === 'dir');
        }
        if (!@ftp_rmdir($conn, $path)) {
            throw new FsException('Cannot delete directory: ' . $path);
        }
    }

    public function rename(string $from, string $to): void
    {
        if (!@ftp_rename($this->handle(), Path::normalise($from), Path::normalise($to))) {
            throw new FsException('Rename failed: ' . $from);
        }
    }

    public function chmod(string $path, int $mode): void
    {
        if (@ftp_chmod($this->handle(), $mode, Path::normalise($path)) === false) {
            throw new FsException('The server rejected the permission change (SITE CHMOD unsupported?).');
        }
    }

    public function readStream(string $path)
    {
        $this->handle();
        $path = Path::normalise($path);

        $handle = @fopen($this->url($path), 'rb', false, $this->streamContext());
        if (is_resource($handle)) {
            return $handle;
        }

        // Wrapper blocked (allow_url_fopen off or firewall): spool through disk.
        $spool = tempnam($this->spoolDir, 'fb-ftp-');
        if ($spool === false) {
            throw new FsException('Cannot create a temporary file for the FTP download.');
        }
        $this->spool[] = $spool;
        $sink = fopen($spool, 'w+b');
        if ($sink === false || !@ftp_fget($this->handle(), $sink, $path, FTP_BINARY)) {
            is_resource($sink) && fclose($sink);
            throw new FsException('Cannot download: ' . $path);
        }
        rewind($sink);

        return $sink;
    }

    public function writeStream(string $path, $handle, int $size = 0, int $offset = 0): void
    {
        $conn = $this->handle();
        $path = Path::normalise($path);
        if (!@ftp_fput($conn, $path, $handle, FTP_BINARY, $offset)) {
            throw new FsException('Upload failed: ' . $path);
        }
    }

    public function realpath(string $path): string
    {
        $conn = $this->handle();
        $path = $path === '' ? $this->home : Path::normalise($path);
        if (@ftp_chdir($conn, $path)) {
            $pwd = @ftp_pwd($conn);

            return Path::normalise($pwd === false ? $path : $pwd);
        }

        return $path;
    }

    public function capabilities(): array
    {
        return ['chmod' => true, 'resume' => true, 'exec' => false, 'owner' => $this->hasMlsd, 'symlink' => false];
    }

    public function describe(): string
    {
        return $this->banner;
    }

    /** @param array<int,array<string,string>> $rows */
    private function fromMlsd(array $rows, string $dir): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? '';
            $type = $row['type'] ?? 'file';
            if ($name === '' || $name === '.' || $name === '..' || $type === 'cdir' || $type === 'pdir') {
                continue;
            }
            $isDir = $type === 'dir';
            $entries[] = new Entry(
                name: $name,
                path: Path::join($dir, $name),
                type: $isDir ? 'dir' : ($type === 'OS.unix=symlink' ? 'link' : 'file'),
                size: (int) ($row['size'] ?? 0),
                mtime: $this->parseMlsdTime($row['modify'] ?? ''),
                perms: isset($row['UNIX.mode']) ? (int) octdec((string) $row['UNIX.mode']) : null,
                owner: (string) ($row['UNIX.owner'] ?? $row['UNIX.ownername'] ?? ''),
                group: (string) ($row['UNIX.group'] ?? $row['UNIX.groupname'] ?? '')
            );
        }

        return $entries;
    }

    /** @param string[] $raw */
    private function fromRawList(array $raw, string $dir): array
    {
        $entries = [];
        foreach ($raw as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^total\s/i', $line)) {
                continue;
            }
            $entry = $this->parseUnixLine($line, $dir) ?? $this->parseDosLine($line, $dir);
            if ($entry !== null && $entry->name !== '.' && $entry->name !== '..') {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function parseUnixLine(string $line, string $dir): ?Entry
    {
        // drwxr-xr-x 3 owner group 4096 Jan 12 09:31 name
        $re = '/^([\-dlbcps])([rwxstST\-]{9})[\+\@]?\s+(\d+)\s+(\S+)\s+(\S+)\s+(\d+)\s+'
            . '(\w{3}\s+\d{1,2}\s+(?:\d{4}|\d{1,2}:\d{2}))\s+(.+)$/';
        if (!preg_match($re, $line, $m)) {
            return null;
        }
        $name   = $m[8];
        $target = null;
        if ($m[1] === 'l' && str_contains($name, ' -> ')) {
            [$name, $target] = explode(' -> ', $name, 2);
        }
        $type = match ($m[1]) {
            'd' => 'dir',
            'l' => 'link',
            default => 'file',
        };

        return new Entry(
            name: $name,
            path: Path::join($dir, $name),
            type: $type,
            size: (int) $m[6],
            mtime: $this->parseListTime($m[7]),
            perms: $this->permsToOctal($m[2]),
            owner: $m[4],
            group: $m[5],
            target: $target,
            targetIsDir: $target !== null && !str_contains(basename($target), '.')
        );
    }

    private function parseDosLine(string $line, string $dir): ?Entry
    {
        // 01-12-24  09:31AM       <DIR>          name
        if (!preg_match('/^(\d{2}-\d{2}-\d{2,4})\s+(\d{2}:\d{2}(?:AM|PM)?)\s+(<DIR>|\d+)\s+(.+)$/i', $line, $m)) {
            return null;
        }
        $isDir = strtoupper($m[3]) === '<DIR>';
        $name  = trim($m[4]);

        return new Entry(
            name: $name,
            path: Path::join($dir, $name),
            type: $isDir ? 'dir' : 'file',
            size: $isDir ? 0 : (int) $m[3],
            mtime: (int) strtotime($m[1] . ' ' . $m[2])
        );
    }

    private function permsToOctal(string $rwx): int
    {
        $mode = 0;
        $map  = ['r' => 4, 'w' => 2, 'x' => 1, 's' => 1, 't' => 1];
        foreach ([0, 3, 6] as $offset) {
            $part = 0;
            for ($i = 0; $i < 3; $i++) {
                $part += $map[strtolower($rwx[$offset + $i])] ?? 0;
            }
            $mode = ($mode << 3) | $part;
        }

        return $mode;
    }

    private function parseMlsdTime(string $value): int
    {
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $value, $m)) {
            return 0;
        }

        return (int) gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function parseListTime(string $value): int
    {
        $time = strtotime($value);
        if ($time === false) {
            return 0;
        }
        // "Jan 12 09:31" has no year: a future date means it was last year.
        if (!preg_match('/\d{4}/', $value) && $time > time() + 86400) {
            $time = (int) strtotime($value . ' -1 year');
        }

        return (int) $time;
    }

    private function url(string $path): string
    {
        return sprintf(
            '%s://%s:%s@%s:%d%s',
            $this->ssl ? 'ftps' : 'ftp',
            rawurlencode($this->username),
            rawurlencode($this->password),
            $this->host,
            $this->port,
            implode('/', array_map('rawurlencode', explode('/', $path)))
        );
    }

    /** @return resource */
    private function streamContext()
    {
        return stream_context_create([
            'ftp' => ['overwrite' => true],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
    }

    private function quote(string $path): string
    {
        return str_contains($path, ' ') ? '"' . $path . '"' : $path;
    }

    private function handle(): Connection
    {
        $this->connect();
        if ($this->conn === null) {
            throw new ConnectionException('FTP connection is not open.');
        }

        return $this->conn;
    }
}
