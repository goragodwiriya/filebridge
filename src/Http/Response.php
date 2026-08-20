<?php

declare(strict_types=1);

namespace FileBridge\Http;

final class Response
{
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function ok(mixed $data = null): never
    {
        self::json(['ok' => true, 'data' => $data]);
    }

    public static function fail(string $message, string $code = 'error', int $status = 400): never
    {
        self::json(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
    }
}
