<?php

declare(strict_types=1);

namespace FileBridge\Fs;

use Closure;

/**
 * Browsing on phpseclib, data on the chosen backend.
 *
 * ext-ssh2 exposes no readdir that carries attributes - see the function list of
 * the extension - so SftpSsh2Driver has to stat every entry separately: one
 * round trip per file, which is what makes a folder of a few hundred files take
 * seconds on a distant server. phpseclib's rawlist() brings names, sizes, dates
 * and permissions back in a single batch. libssh2 keeps the actual transfers,
 * where its C crypto is the faster of the two.
 *
 * Neither side connects until something asks it to, so a plain browse opens one
 * connection and so does a plain download.
 */
final class HybridDriver implements DriverInterface, ExecCapable
{
    private ?DriverInterface $meta = null;

    /** @param Closure():DriverInterface $metaFactory builds the listing backend */
    public function __construct(
        private readonly DriverInterface $primary,
        private readonly Closure $metaFactory
    ) {
    }

    public function connect(): void
    {
        // Browsing is what normally follows; the data side waits until it is needed.
        $this->meta()->connect();
    }

    public function disconnect(): void
    {
        $this->primary->disconnect();
        if ($this->meta !== null && $this->meta !== $this->primary) {
            $this->meta->disconnect();
        }
        $this->meta = null;
    }

    // ------------------------------------------------ walking the tree: batched

    public function list(string $path): array
    {
        return $this->meta()->list($path);
    }

    public function realpath(string $path): string
    {
        return $this->meta()->realpath($path);
    }

    public function home(): string
    {
        return $this->meta()->home();
    }

    public function describe(): string
    {
        return $this->meta()->describe();
    }

    // ------------------------------------------- single path work: chosen backend

    public function stat(string $path): ?Entry
    {
        return $this->primary->stat($path);
    }

    public function exists(string $path): bool
    {
        return $this->primary->exists($path);
    }

    public function mkdir(string $path): void
    {
        $this->primary->mkdir($path);
    }

    public function delete(string $path, bool $recursive = false): void
    {
        $this->primary->delete($path, $recursive);
    }

    public function rename(string $from, string $to): void
    {
        $this->primary->rename($from, $to);
    }

    public function chmod(string $path, int $mode): void
    {
        $this->primary->chmod($path, $mode);
    }

    public function readStream(string $path)
    {
        return $this->primary->readStream($path);
    }

    public function writeStream(string $path, $handle, int $size = 0, int $offset = 0): void
    {
        $this->primary->writeStream($path, $handle, $size, $offset);
    }

    public function capabilities(): array
    {
        return $this->primary->capabilities();
    }

    public function exec(string $command): string
    {
        return $this->primary instanceof ExecCapable ? $this->primary->exec($command) : '';
    }

    private function meta(): DriverInterface
    {
        if ($this->meta !== null) {
            return $this->meta;
        }
        // When the preferred backend already fell back to phpseclib there is
        // nothing to gain from a second connection to the same server.
        $inner = $this->primary instanceof FallbackDriver ? $this->primary->active() : $this->primary;

        return $this->meta = $inner instanceof SftpSeclibDriver ? $inner : ($this->metaFactory)();
    }
}
