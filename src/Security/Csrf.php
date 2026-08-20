<?php

declare(strict_types=1);

namespace FileBridge\Security;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf'];
    }

    public static function check(?string $given): bool
    {
        return is_string($given)
            && !empty($_SESSION['csrf'])
            && hash_equals($_SESSION['csrf'], $given);
    }
}
