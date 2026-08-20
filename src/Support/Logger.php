<?php

declare(strict_types=1);

namespace FileBridge\Support;

final class Logger
{
    public function __construct(private readonly string $dir)
    {
    }

    public function audit(string $user, string $action, array $context = []): void
    {
        $this->write('audit', sprintf(
            '%s | %s | %s | %s | %s',
            date('c'),
            $_SERVER['REMOTE_ADDR'] ?? 'cli',
            $user !== '' ? $user : '-',
            $action,
            json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ));
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', sprintf(
            '%s | %s | %s',
            date('c'),
            $message,
            json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ));
    }

    private function write(string $channel, string $line): void
    {
        $file = $this->dir . '/' . $channel . '-' . date('Y-m') . '.log';
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
