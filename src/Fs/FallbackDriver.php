<?php

declare(strict_types=1);

namespace FileBridge\Fs;

use Closure;
use Throwable;

/**
 * Tries a preferred driver and transparently switches to a spare one if the
 * first cannot establish a connection. A host key mismatch is never retried -
 * that failure has to reach the operator.
 */
final class FallbackDriver implements DriverInterface, ExecCapable
{
    private ?DriverInterface $active = null;

    public function __construct(
        private readonly DriverInterface $preferred,
        private readonly Closure $spare
    ) {
    }

    public function connect(): void
    {
        if ($this->active !== null) {
            return;
        }
        try {
            $this->preferred->connect();
            $this->active = $this->preferred;

            return;
        } catch (ConnectionException $e) {
            $this->preferred->disconnect();
            if (str_contains($e->getMessage(), 'HOST KEY CHANGED')) {
                throw $e;
            }
        } catch (Throwable) {
            $this->preferred->disconnect();
            // fall through to the spare backend
        }

        $spare = ($this->spare)();
        $spare->connect();
        $this->active = $spare;
    }

    public function exec(string $command): string
    {
        $driver = $this->driver();

        return $driver instanceof ExecCapable ? $driver->exec($command) : '';
    }

    public function disconnect(): void
    {
        $this->active?->disconnect();
        $this->active = null;
    }

    public function list(string $path): array
    {
        return $this->driver()->list($path);
    }

    public function stat(string $path): ?Entry
    {
        return $this->driver()->stat($path);
    }

    public function exists(string $path): bool
    {
        return $this->driver()->exists($path);
    }

    public function mkdir(string $path): void
    {
        $this->driver()->mkdir($path);
    }

    public function delete(string $path, bool $recursive = false): void
    {
        $this->driver()->delete($path, $recursive);
    }

    public function rename(string $from, string $to): void
    {
        $this->driver()->rename($from, $to);
    }

    public function chmod(string $path, int $mode): void
    {
        $this->driver()->chmod($path, $mode);
    }

    public function readStream(string $path)
    {
        return $this->driver()->readStream($path);
    }

    public function writeStream(string $path, $handle, int $size = 0, int $offset = 0): void
    {
        $this->driver()->writeStream($path, $handle, $size, $offset);
    }

    public function realpath(string $path): string
    {
        return $this->driver()->realpath($path);
    }

    public function home(): string
    {
        return $this->driver()->home();
    }

    public function capabilities(): array
    {
        return $this->driver()->capabilities();
    }

    public function describe(): string
    {
        return $this->driver()->describe();
    }

    private function driver(): DriverInterface
    {
        $this->connect();

        return $this->active ?? throw new ConnectionException('No SFTP backend could connect.');
    }
}
