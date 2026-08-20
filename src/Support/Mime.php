<?php

declare(strict_types=1);

namespace FileBridge\Support;

final class Mime
{
    /** extension => [icon id in the SVG sprite, colour token] */
    private const MAP = [
        'php'  => ['file-code', 'php'],   'phtml' => ['file-code', 'php'],
        'js'   => ['file-code', 'js'],    'mjs'   => ['file-code', 'js'],
        'ts'   => ['file-code', 'js'],    'json'  => ['file-code', 'js'],
        'html' => ['file-code', 'html'],  'htm'   => ['file-code', 'html'],
        'xml'  => ['file-code', 'html'],  'vue'   => ['file-code', 'html'],
        'css'  => ['file-code', 'css'],   'scss'  => ['file-code', 'css'],
        'less' => ['file-code', 'css'],
        'py'   => ['file-code', 'py'],    'rb'    => ['file-code', 'py'],
        'sh'   => ['file-code', 'sh'],    'bash'  => ['file-code', 'sh'],
        'sql'  => ['file-db',   'sql'],   'db'    => ['file-db', 'sql'],
        'sqlite' => ['file-db', 'sql'],
        'md'   => ['file-text', 'doc'],   'txt'   => ['file-text', 'doc'],
        'log'  => ['file-text', 'doc'],   'ini'   => ['file-text', 'doc'],
        'env'  => ['file-text', 'doc'],   'conf'  => ['file-text', 'doc'],
        'yml'  => ['file-text', 'doc'],   'yaml'  => ['file-text', 'doc'],
        'pdf'  => ['file-pdf',  'pdf'],
        'doc'  => ['file-text', 'word'],  'docx'  => ['file-text', 'word'],
        'xls'  => ['file-sheet','excel'], 'xlsx'  => ['file-sheet', 'excel'],
        'csv'  => ['file-sheet','excel'],
        'ppt'  => ['file-text', 'ppt'],   'pptx'  => ['file-text', 'ppt'],
        'zip'  => ['file-zip',  'zip'],   'gz'    => ['file-zip', 'zip'],
        'tar'  => ['file-zip',  'zip'],   'rar'   => ['file-zip', 'zip'],
        '7z'   => ['file-zip',  'zip'],   'bz2'   => ['file-zip', 'zip'],
        'xz'   => ['file-zip',  'zip'],   'tgz'   => ['file-zip', 'zip'],
        'jpg'  => ['file-image','img'],   'jpeg'  => ['file-image', 'img'],
        'png'  => ['file-image','img'],   'gif'   => ['file-image', 'img'],
        'webp' => ['file-image','img'],   'svg'   => ['file-image', 'img'],
        'ico'  => ['file-image','img'],   'bmp'   => ['file-image', 'img'],
        'avif' => ['file-image','img'],
        'mp4'  => ['file-video','video'], 'mkv'   => ['file-video', 'video'],
        'avi'  => ['file-video','video'], 'mov'   => ['file-video', 'video'],
        'webm' => ['file-video','video'],
        'mp3'  => ['file-audio','audio'], 'wav'   => ['file-audio', 'audio'],
        'flac' => ['file-audio','audio'], 'ogg'   => ['file-audio', 'audio'],
        'ttf'  => ['file-font', 'font'],  'otf'   => ['file-font', 'font'],
        'woff' => ['file-font', 'font'],  'woff2' => ['file-font', 'font'],
        'key'  => ['file-key',  'key'],   'pem'   => ['file-key', 'key'],
        'crt'  => ['file-key',  'key'],   'pub'   => ['file-key', 'key'],
    ];

    private const EDITABLE = [
        'txt', 'md', 'log', 'ini', 'env', 'conf', 'cfg', 'yml', 'yaml', 'json', 'xml',
        'php', 'phtml', 'js', 'mjs', 'ts', 'jsx', 'tsx', 'vue', 'css', 'scss', 'less',
        'html', 'htm', 'sql', 'sh', 'bash', 'py', 'rb', 'go', 'java', 'c', 'cpp', 'h',
        'htaccess', 'gitignore', 'lock', 'svg', 'csv', 'tpl', 'twig', 'blade',
    ];

    public static function icon(string $ext): string
    {
        return self::MAP[strtolower($ext)][0] ?? 'file';
    }

    public static function colour(string $ext): string
    {
        return self::MAP[strtolower($ext)][1] ?? 'default';
    }

    public static function editable(string $ext): bool
    {
        return in_array(strtolower($ext), self::EDITABLE, true);
    }

    public static function contentType(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
            'pdf'  => 'application/pdf',
            'txt', 'log', 'md' => 'text/plain; charset=utf-8',
            default => 'application/octet-stream',
        };
    }
}
