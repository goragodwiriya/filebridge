<?php

declare(strict_types=1);

namespace FileBridge\Transfer;

use php_user_filter;

/**
 * Counts bytes as they flow off a source stream so the engine can report
 * progress inside a single large file - and stop mid-file when the job is
 * cancelled (the callback returns false, the read then fails cleanly).
 */
final class ProgressFilter extends php_user_filter
{
    public const NAME = 'filebridge.progress';

    private static bool $registered = false;

    public static function register(): void
    {
        if (!self::$registered) {
            stream_filter_register(self::NAME, self::class);
            self::$registered = true;
        }
    }

    /** @param resource $stream */
    public static function attach($stream, callable $onBytes): void
    {
        self::register();
        stream_filter_append($stream, self::NAME, STREAM_FILTER_READ, ['onBytes' => $onBytes]);
    }

    #[\ReturnTypeWillChange]
    public function filter($in, $out, &$consumed, $closing): int
    {
        $abort = false;
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            $callback = is_array($this->params) ? ($this->params['onBytes'] ?? null) : null;
            if (is_callable($callback) && $callback($bucket->datalen) === false) {
                $abort = true;
            }
            stream_bucket_append($out, $bucket);
        }

        return $abort ? PSFS_ERR_FATAL : PSFS_PASS_ON;
    }
}
