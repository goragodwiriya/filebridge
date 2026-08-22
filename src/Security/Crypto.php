<?php

declare(strict_types=1);

namespace FileBridge\Security;

use FileBridge\Support\Lang;
use RuntimeException;

/**
 * Secret-at-rest encryption for stored credentials.
 * Uses libsodium's authenticated secretbox with a per-installation key.
 */
final class Crypto
{
    private string $key;

    public function __construct(private readonly string $keyFile)
    {
        $this->key = $this->loadKey();
    }

    public function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $this->key);

        return 'v1:' . base64_encode($nonce . $cipher);
    }

    public function decrypt(string $payload): string
    {
        if ($payload === '') {
            return '';
        }
        if (!str_starts_with($payload, 'v1:')) {
            return $payload; // plain value written by hand into sites.json
        }
        $raw = base64_decode(substr($payload, 3), true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1) {
            throw new RuntimeException(Lang::t('err.secret_corrupt'));
        }
        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plain === false) {
            throw new RuntimeException(Lang::t('err.secret_key'));
        }

        return $plain;
    }

    private function loadKey(): string
    {
        if (is_file($this->keyFile)) {
            /** @var string $stored */
            $stored = require $this->keyFile;
            $key    = base64_decode($stored, true);
            if ($key !== false && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $key;
            }
        }

        $key = sodium_crypto_secretbox_keygen();
        $ok  = @file_put_contents(
            $this->keyFile,
            "<?php\n// FileBridge master key - keep this file secret and back it up.\nreturn '" . base64_encode($key) . "';\n",
            LOCK_EX
        );
        if ($ok === false) {
            throw new RuntimeException(Lang::t('err.write_key', ['file' => $this->keyFile]));
        }
        @chmod($this->keyFile, 0600);

        return $key;
    }
}
