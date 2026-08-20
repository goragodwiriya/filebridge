<?php

declare(strict_types=1);

namespace FileBridge\Support;

final class Bytes
{
    /** Human readable size, e.g. 1.4 MB */
    public static function human(int|float $bytes, int $precision = 1): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = (int) floor(log((float) $bytes, 1024));
        $power = min($power, count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return round($value, $power === 0 ? 0 : $precision) . ' ' . $units[$power];
    }

    /** Parse "8M", "512K", "1G" (php.ini style) into bytes. */
    public static function parse(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower($value[strlen($value) - 1]);
        $num  = (int) $value;

        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }
}
