<?php

declare(strict_types=1);

namespace FileBridge\Fs;

use FileBridge\App;
use FileBridge\Site\Site;

/** Turns a saved connection profile into a live driver. */
final class DriverFactory
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param bool $bulk true for a connection that only moves file contents, so
     *                   it skips the listing backend and its extra connection
     */
    public function make(Site $site, bool $bulk = false): DriverInterface
    {
        return match ($site->protocol) {
            'local' => $this->local($site),
            'ftp'   => $this->ftp($site, false),
            'ftps'  => $this->ftp($site, true),
            default => $this->sftp($site, $bulk),
        };
    }

    /** Build and connect, mapping any failure to a readable message. */
    public function connect(Site $site, bool $bulk = false): DriverInterface
    {
        $driver = $this->make($site, $bulk);
        $driver->connect();

        return $driver;
    }

    private function local(Site $site): LocalDriver
    {
        $roots = array_values(array_filter(array_map(
            'strval',
            (array) ($this->app->config['local_roots'] ?? [])
        )));
        if ($roots === []) {
            // Sensible default: the directory FileBridge itself sits in.
            $roots = [dirname($this->app->base)];
        }

        return new LocalDriver(roots: $roots, start: $site->rootPath);
    }

    private function ftp(Site $site, bool $ssl): FtpDriver
    {
        return new FtpDriver(
            host: $site->host,
            port: $site->port ?: 21,
            username: $site->username !== '' ? $site->username : 'anonymous',
            password: $site->password,
            ssl: $ssl,
            passive: $site->passive,
            timeout: $site->timeout,
            startPath: $site->rootPath,
            spoolDir: $this->app->base . '/storage/tmp'
        );
    }

    /**
     * ext-ssh2 first when it is installed and the profile allows it; if that
     * backend cannot connect we retry on phpseclib rather than failing outright.
     */
    private function sftp(Site $site, bool $bulk = false): DriverInterface
    {
        $preferExt = $site->backend === 'ssh2'
            || ($site->backend === 'auto' && SftpSsh2Driver::available());

        if ($preferExt && SftpSsh2Driver::available()) {
            $driver = new SftpSsh2Driver(
                host: $site->host,
                port: $site->port ?: 22,
                username: $site->username,
                password: $site->auth === 'key' ? '' : $site->password,
                privateKey: $site->auth === 'key' ? $site->privateKey : '',
                passphrase: $site->passphrase,
                timeout: $site->timeout,
                startPath: $site->rootPath,
                hostKeys: $this->app->hostKeys(),
                verifyHostKey: (bool) $this->app->config['verify_host_key']
            );
            $primary = $site->backend === 'ssh2'
                ? $driver
                : new FallbackDriver($driver, fn (): DriverInterface => $this->seclib($site));

            // Moving bytes wants libssh2 alone; browsing gets phpseclib's batched
            // directory listing in front of it.
            return $bulk
                ? $primary
                : new HybridDriver($primary, fn (): DriverInterface => $this->seclib($site));
        }

        return $this->seclib($site);
    }

    private function seclib(Site $site): SftpSeclibDriver
    {
        return new SftpSeclibDriver(
            host: $site->host,
            port: $site->port ?: 22,
            username: $site->username,
            password: $site->auth === 'key' ? '' : $site->password,
            privateKey: $site->auth === 'key' ? $site->privateKey : '',
            passphrase: $site->passphrase,
            timeout: $site->timeout,
            startPath: $site->rootPath,
            hostKeys: $this->app->hostKeys(),
            verifyHostKey: (bool) $this->app->config['verify_host_key']
        );
    }
}
