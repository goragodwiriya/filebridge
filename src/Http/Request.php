<?php

declare(strict_types=1);

namespace FileBridge\Http;

final class Request
{
    public readonly array $input;

    public function __construct()
    {
        $raw  = file_get_contents('php://input') ?: '';
        $json = json_decode($raw, true);
        $this->input = is_array($json) ? $json : $_POST;
    }

    public function action(): string
    {
        return (string) ($this->input['action'] ?? $_GET['action'] ?? '');
    }

    public function token(): ?string
    {
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        return is_string($header) ? $header : (isset($this->input['_token']) ? (string) $this->input['_token'] : null);
    }

    public function str(string $key, string $default = ''): string
    {
        $value = $this->input[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        return isset($this->input[$key]) && is_numeric($this->input[$key]) ? (int) $this->input[$key] : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return isset($this->input[$key]) ? filter_var($this->input[$key], FILTER_VALIDATE_BOOLEAN) : $default;
    }

    public function arr(string $key): array
    {
        return isset($this->input[$key]) && is_array($this->input[$key]) ? $this->input[$key] : [];
    }
}
