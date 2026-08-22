<?php

declare(strict_types=1);

namespace FileBridge\Support;

/**
 * Translation table for the whole app - PHP and browser alike.
 *
 * One flat file per language in lang/, keys dotted by area ("panel.filter").
 * English is always loaded underneath the chosen language, so a key that is
 * missing from a translation falls back to readable English instead of showing
 * its own name. Lang::all() hands the same table to the browser, which is why
 * the client never has to ask for a string twice.
 *
 * Plurals: pass a `count` placeholder and add a "<key>_plural" entry for the
 * languages that need one. Thai has no plural form, so it simply omits them.
 */
final class Lang
{
    /** Languages the UI ships with, in the order the switcher lists them. */
    public const AVAILABLE = ['en' => 'English', 'th' => 'ไทย'];

    /** Name of the cookie the language switcher writes. */
    public const COOKIE = 'fb_lang';

    private const FALLBACK = 'en';

    private static string $dir = '';
    private static string $code = self::FALLBACK;

    /** @var array<string,string> */
    private static array $strings = [];

    /** @var array<string,string> */
    private static array $fallback = [];

    /**
     * Pick the language for this request.
     *
     * The cookie wins because it is the choice the operator just made in the
     * switcher; `default_language` decides for a browser that has never been
     * here, and "auto" hands that decision to Accept-Language.
     */
    public static function boot(string $dir, string $configured = 'auto'): void
    {
        self::$dir      = rtrim($dir, '/');
        self::$fallback = self::load(self::FALLBACK);

        $cookie = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (self::supports($cookie)) {
            self::set($cookie);

            return;
        }
        if (self::supports($configured)) {
            self::set($configured);

            return;
        }
        self::set(self::detect());
    }

    public static function set(string $code): void
    {
        self::$code    = self::supports($code) ? $code : self::FALLBACK;
        self::$strings = self::$code === self::FALLBACK ? self::$fallback : self::load(self::$code);
    }

    public static function code(): string
    {
        return self::$code;
    }

    public static function supports(string $code): bool
    {
        return $code !== '' && isset(self::AVAILABLE[$code]);
    }

    /** @param array<string,string|int|float> $vars */
    public static function t(string $key, array $vars = []): string
    {
        // Which table the wording came from decides which plural may apply:
        // Thai translates "{count} file" and has no plural twin, and borrowing
        // English's "{count} files" there would put English back on screen.
        $own  = isset(self::$strings[$key]);
        $text = $own ? self::$strings[$key] : (self::$fallback[$key] ?? $key);

        if (isset($vars['count']) && (int) $vars['count'] !== 1) {
            $plural = $own
                ? (self::$strings[$key . '_plural'] ?? null)
                : (self::$fallback[$key . '_plural'] ?? null);
            $text = $plural ?? $text;
        }
        if ($vars === []) {
            return $text;
        }

        $replace = [];
        foreach ($vars as $name => $value) {
            $replace['{' . $name . '}'] = (string) $value;
        }

        return strtr($text, $replace);
    }

    /**
     * The whole table for the current language, for the browser.
     *
     * Fallback plurals are dropped for keys the language translates itself, so
     * t() in the browser makes the same choice this class does.
     */
    public static function all(): array
    {
        $all = self::$strings + self::$fallback;
        foreach (self::$strings as $key => $ignored) {
            if (!isset(self::$strings[$key . '_plural'])) {
                unset($all[$key . '_plural']);
            }
        }

        return $all;
    }

    /** @return array<string,string> */
    private static function load(string $code): array
    {
        $file = self::$dir . '/' . $code . '.php';

        return is_file($file) ? (array) require $file : [];
    }

    /** First Accept-Language entry the app can actually speak. */
    private static function detect(): string
    {
        $header = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        foreach (explode(',', $header) as $part) {
            $code = strtolower(trim(explode('-', explode(';', $part)[0])[0]));
            if (self::supports($code)) {
                return $code;
            }
        }

        return self::FALLBACK;
    }
}
