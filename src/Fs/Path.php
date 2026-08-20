<?php

declare(strict_types=1);

namespace FileBridge\Fs;

/** POSIX-style path helpers. Every driver speaks forward slashes. */
final class Path
{
    /** Collapse ".", "..", duplicate slashes. Result always starts with "/". */
    public static function normalise(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || $path === '.') {
            return '/';
        }
        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        return '/' . implode('/', $out);
    }

    public static function join(string $base, string ...$parts): string
    {
        $path = $base;
        foreach ($parts as $part) {
            $path = rtrim($path, '/') . '/' . ltrim($part, '/');
        }

        return self::normalise($path);
    }

    public static function parent(string $path): string
    {
        $path = self::normalise($path);
        if ($path === '/') {
            return '/';
        }

        return self::normalise(substr($path, 0, (int) strrpos($path, '/')) ?: '/');
    }

    public static function name(string $path): string
    {
        $path = self::normalise($path);

        return $path === '/' ? '/' : substr($path, (int) strrpos($path, '/') + 1);
    }

    /** True when $child sits inside $parent (or is $parent). */
    public static function contains(string $parent, string $child): bool
    {
        $parent = rtrim(self::normalise($parent), '/');
        $child  = rtrim(self::normalise($child), '/');
        if ($parent === '') {
            return true;
        }

        return $child === $parent || str_starts_with($child, $parent . '/');
    }

    /** Strip anything that could break out of a directory when used as a file name. */
    public static function safeName(string $name): string
    {
        $name = str_replace(['/', '\\', "\0"], '', trim($name));

        return $name === '.' || $name === '..' ? '' : $name;
    }

    /** "index.php" + suffix " (1)" => "index (1).php" */
    public static function withSuffix(string $name, string $suffix): string
    {
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $stem = $ext === '' ? $name : substr($name, 0, -(strlen($ext) + 1));

        return $ext === '' ? $stem . $suffix : $stem . $suffix . '.' . $ext;
    }
}
