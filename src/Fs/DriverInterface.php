<?php

declare(strict_types=1);

namespace FileBridge\Fs;

/**
 * One uniform contract for every protocol. The UI and the transfer engine
 * only ever talk to this - they never learn which protocol is behind it.
 */
interface DriverInterface
{
    public function connect(): void;

    public function disconnect(): void;

    /** @return Entry[] excluding "." and ".." */
    public function list(string $path): array;

    public function stat(string $path): ?Entry;

    public function exists(string $path): bool;

    public function mkdir(string $path): void;

    /** @param bool $recursive delete directory contents too */
    public function delete(string $path, bool $recursive = false): void;

    public function rename(string $from, string $to): void;

    public function chmod(string $path, int $mode): void;

    /** @return resource */
    public function readStream(string $path);

    /**
     * @param resource $handle source stream, already positioned
     * @param int      $offset byte offset to start writing at (resume)
     */
    public function writeStream(string $path, $handle, int $size = 0, int $offset = 0): void;

    /** Absolute, canonical path (home directory when given ""). */
    public function realpath(string $path): string;

    /** Starting directory of the connection. */
    public function home(): string;

    /**
     * @return array{chmod:bool,resume:bool,exec:bool,owner:bool,symlink:bool}
     */
    public function capabilities(): array;

    /** Short label describing the live connection, e.g. "SFTP - OpenSSH_9.6". */
    public function describe(): string;

    /**
     * The library or extension actually doing the work, e.g. "ext-ssh2".
     * Answerable without a connection, so a label never opens one.
     */
    public function backend(): string;
}
