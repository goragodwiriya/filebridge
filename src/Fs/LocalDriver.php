<?php

declare(strict_types=1);

namespace FileBridge\Fs;

use FileBridge\Support\Lang;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** The machine running FileBridge, restricted to the configured roots. */
final class LocalDriver implements DriverInterface
{
    /** @param string[] $roots */
    public function __construct(
        private readonly array $roots,
        private readonly string $start = ''
    ) {
    }

    public function connect(): void
    {
        if ($this->roots === []) {
            throw new ConnectionException(Lang::t('fs.no_roots'));
        }
    }

    public function disconnect(): void
    {
    }

    public function home(): string
    {
        $start = $this->start !== '' ? Path::normalise($this->start) : (string) $this->roots[0];

        return $this->allowed($start) ? $start : Path::normalise((string) $this->roots[0]);
    }

    public function list(string $path): array
    {
        $path = $this->guard($path);
        if (!is_dir($path)) {
            throw new FsException(Lang::t('fs.not_dir', ['path' => $path]));
        }
        $handle = @opendir($path);
        if ($handle === false) {
            throw new FsException(Lang::t('fs.denied', ['path' => $path]));
        }

        $entries = [];
        while (($name = readdir($handle)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = Path::join($path, $name);
            $entries[] = $this->toEntry($name, $full);
        }
        closedir($handle);

        return $entries;
    }

    public function stat(string $path): ?Entry
    {
        $path = $this->guard($path);
        if (!file_exists($path) && !is_link($path)) {
            return null;
        }

        return $this->toEntry(Path::name($path), $path);
    }

    public function exists(string $path): bool
    {
        return file_exists($this->guard($path));
    }

    public function mkdir(string $path): void
    {
        $path = $this->guard($path);
        if (is_dir($path)) {
            return;
        }
        if (!@mkdir($path, 0755, true) && !is_dir($path)) {
            throw new FsException(Lang::t('fs.mkdir', ['path' => $path]));
        }
    }

    public function delete(string $path, bool $recursive = false): void
    {
        $path = $this->guard($path);
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                throw new FsException(Lang::t('fs.delete', ['path' => $path]));
            }

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        if (!$recursive) {
            if (!@rmdir($path)) {
                throw new FsException(Lang::t('fs.not_empty', ['path' => $path]));
            }

            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        if (!@rmdir($path)) {
            throw new FsException(Lang::t('fs.rmdir', ['path' => $path]));
        }
    }

    public function rename(string $from, string $to): void
    {
        if (!@rename($this->guard($from), $this->guard($to))) {
            throw new FsException(Lang::t('fs.rename', ['path' => $from]));
        }
    }

    public function chmod(string $path, int $mode): void
    {
        if (!@chmod($this->guard($path), $mode)) {
            throw new FsException(Lang::t('fs.chmod', ['path' => $path]));
        }
    }

    public function readStream(string $path)
    {
        $handle = @fopen($this->guard($path), 'rb');
        if ($handle === false) {
            throw new FsException(Lang::t('fs.read', ['path' => $path]));
        }
        Stream::prepare($handle);

        return $handle;
    }

    public function writeStream(string $path, $handle, int $size = 0, int $offset = 0): void
    {
        $path = $this->guard($path);
        $out  = @fopen($path, $offset > 0 ? 'cb' : 'wb');
        if ($out === false) {
            throw new FsException(Lang::t('fs.write', ['path' => $path]));
        }
        if ($offset > 0) {
            fseek($out, $offset);
        }
        try {
            Stream::copy($handle, $out);
        } finally {
            fclose($out);
        }
    }

    public function realpath(string $path): string
    {
        $path = $path === '' ? $this->home() : Path::normalise($path);
        $real = realpath($path);

        return $this->guard($real === false ? $path : $real);
    }

    public function capabilities(): array
    {
        return ['chmod' => true, 'resume' => true, 'exec' => false, 'owner' => true, 'symlink' => true];
    }

    public function describe(): string
    {
        return Lang::t('fs.local');
    }

    /** @return string[] the roots this driver may browse */
    public function roots(): array
    {
        return array_map(static fn ($r) => Path::normalise((string) $r), $this->roots);
    }

    private function toEntry(string $name, string $full): Entry
    {
        $isLink = is_link($full);
        $stat   = @stat($full);
        $type   = $isLink ? 'link' : (is_dir($full) ? 'dir' : 'file');

        $entry = new Entry(
            name: $name,
            path: $full,
            type: $type,
            size: $type === 'dir' ? 0 : (int) ($stat['size'] ?? 0),
            mtime: (int) ($stat['mtime'] ?? 0),
            perms: isset($stat['mode']) ? ((int) $stat['mode'] & 0777) : null,
            owner: $this->userName((int) ($stat['uid'] ?? -1)),
            group: $this->groupName((int) ($stat['gid'] ?? -1)),
            target: $isLink ? (readlink($full) ?: null) : null,
            targetIsDir: $isLink && is_dir($full)
        );

        return $entry;
    }

    private function userName(int $uid): string
    {
        if ($uid < 0 || !function_exists('posix_getpwuid')) {
            return (string) $uid;
        }
        $info = posix_getpwuid($uid);

        return $info['name'] ?? (string) $uid;
    }

    private function groupName(int $gid): string
    {
        if ($gid < 0 || !function_exists('posix_getgrgid')) {
            return (string) $gid;
        }
        $info = posix_getgrgid($gid);

        return $info['name'] ?? (string) $gid;
    }

    private function allowed(string $path): bool
    {
        foreach ($this->roots as $root) {
            if (Path::contains((string) $root, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalise and refuse anything outside the configured roots, including a
     * path that only lands outside once its symlinks are resolved.
     */
    private function guard(string $path): string
    {
        $path = $path === '' ? $this->home() : Path::normalise($path);
        if (!$this->allowed($path)) {
            throw new FsException(Lang::t('fs.outside_roots', ['path' => $path]));
        }
        $real = realpath($path);
        if ($real !== false && !$this->allowed(Path::normalise($real))) {
            throw new FsException(Lang::t('fs.outside_resolved', ['path' => $path]));
        }

        return $path;
    }
}
