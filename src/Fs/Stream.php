<?php

declare(strict_types=1);

namespace FileBridge\Fs;

use FileBridge\Support\Lang;

/**
 * Stream copying that honours the configured chunk size.
 *
 * PHP's stream_copy_to_stream() moves 8 KB per iteration no matter what the
 * stream is set to, and over SFTP every one of those blocks costs a round trip:
 * a long distance link ends up limited by latency instead of bandwidth. Reading
 * and writing in chunk_size blocks lets the SSH layer keep several packets in
 * flight at once.
 */
final class Stream
{
    public const DEFAULT_CHUNK = 262144;

    private static int $chunk = self::DEFAULT_CHUNK;

    /** Set once at boot from config['chunk_size']. */
    public static function useChunkSize(int $bytes): void
    {
        // Under 8 KB there is nothing to win; over 8 MB the buffers start to hurt.
        self::$chunk = max(8192, min($bytes, 8388608));
    }

    public static function chunkSize(): int
    {
        return self::$chunk;
    }

    /** @param resource $handle */
    public static function prepare($handle): void
    {
        if (is_resource($handle)) {
            @stream_set_chunk_size($handle, self::$chunk);
        }
    }

    /**
     * @param  resource $from
     * @param  resource $to
     * @return int bytes copied
     */
    public static function copy($from, $to): int
    {
        self::prepare($from);
        self::prepare($to);

        $chunk  = self::$chunk;
        $copied = 0;

        while (!feof($from)) {
            $data = fread($from, $chunk);
            if ($data === false || $data === '') {
                // A cancelled job kills its read filter, which surfaces here.
                break;
            }
            $length  = strlen($data);
            $written = 0;
            while ($written < $length) {
                $step = fwrite($to, $written === 0 ? $data : substr($data, $written));
                if ($step === false || $step === 0) {
                    throw new FsException(Lang::t('fs.short_write', ['bytes' => $copied + $written]));
                }
                $written += $step;
            }
            $copied += $length;
        }

        return $copied;
    }
}
