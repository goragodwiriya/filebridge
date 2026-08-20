<?php

declare(strict_types=1);

namespace FileBridge\Http;

use FileBridge\App;
use FileBridge\Fs\DriverFactory;
use FileBridge\Fs\DriverInterface;
use FileBridge\Fs\LocalDriver;
use FileBridge\Fs\Path;
use FileBridge\Security\Csrf;
use FileBridge\Site\Site;
use FileBridge\Support\Bytes;
use Throwable;

/** Every JSON action the browser can call. */
final class Api
{
    /** @var array<string,DriverInterface> */
    private array $drivers = [];

    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly App $app,
        private readonly Request $request
    ) {
    }

    public function handle(): never
    {
        $action = $this->request->action();

        try {
            if (!$this->app->ipAllowed()) {
                Response::fail('Your address is not allowed to use this tool.', 'forbidden', 403);
            }

            // Reachable without a session.
            match (true) {
                $action === 'app.state'   => $this->appState(),
                $action === 'auth.setup'  => $this->authSetup(),
                $action === 'auth.login'  => $this->authLogin(),
                default                   => null,
            };

            if (!$this->app->auth()->check()) {
                Response::fail('Please sign in again.', 'unauthenticated', 401);
            }
            if (!Csrf::check($this->request->token())) {
                Response::fail('Security token expired - reload the page.', 'csrf', 419);
            }

            match ($action) {
                'auth.logout'      => $this->authLogout(),
                'auth.password'    => $this->authPassword(),
                'site.list'        => $this->siteList(),
                'site.save'        => $this->siteSave(),
                'site.delete'      => $this->siteDelete(),
                'site.test'        => $this->siteTest(),
                'hostkey.forget'   => $this->hostKeyForget(),
                'fs.list'          => $this->fsList(),
                'fs.mkdir'         => $this->fsMkdir(),
                'fs.rename'        => $this->fsRename(),
                'fs.delete'        => $this->fsDelete(),
                'fs.chmod'         => $this->fsChmod(),
                'fs.read'          => $this->fsRead(),
                'fs.write'         => $this->fsWrite(),
                'fs.upload'        => $this->fsUpload(),
                'transfer.enqueue' => $this->transferEnqueue(),
                'transfer.list'    => $this->transferList(),
                'transfer.cancel'  => $this->transferCancel(),
                'transfer.retry'   => $this->transferRetry(),
                'transfer.clear'   => $this->transferClear(),
                'transfer.run'     => $this->transferRun(),
                default            => Response::fail('Unknown action: ' . $action, 'unknown_action', 404),
            };
        } catch (Throwable $e) {
            $this->app->logger()->error($e->getMessage(), ['action' => $action]);
            Response::fail($e->getMessage(), 'exception', 400);
        }

        Response::fail('No response produced.', 'empty', 500);
    }

    // ------------------------------------------------------------------- state

    private function appState(): never
    {
        $auth = $this->app->auth();
        $data = [
            'appName'    => $this->app->config['app_name'],
            'needsSetup' => $auth->needsSetup(),
            'signedIn'   => $auth->check(),
            'user'       => $auth->username(),
            'csrf'       => Csrf::token(),
            'settings'   => [
                'theme'         => $this->app->config['default_theme'],
                'showHidden'    => (bool) $this->app->config['show_hidden'],
                'dateFormat'    => $this->app->config['date_format'],
                'maxEditSize'   => (int) $this->app->config['max_edit_size'],
                'maxUploadSize' => $this->uploadLimit(),
            ],
            'backends'   => [
                'ssh2'      => extension_loaded('ssh2'),
                'phpseclib' => class_exists(\phpseclib3\Net\SFTP::class),
                'ftp'       => extension_loaded('ftp'),
            ],
        ];
        if ($auth->check()) {
            $data['sites'] = $this->siteListArray();
        }

        Response::ok($data);
    }

    private function authSetup(): never
    {
        $auth = $this->app->auth();
        if (!$auth->needsSetup()) {
            Response::fail('Setup has already been completed.', 'setup_done');
        }
        $auth->createAdmin($this->request->str('username'), $this->request->str('password'));
        $auth->attempt($this->request->str('username'), $this->request->str('password'));
        $this->app->logger()->audit($this->request->str('username'), 'auth.setup');

        Response::ok(['csrf' => Csrf::token(), 'user' => $auth->username()]);
    }

    private function authLogin(): never
    {
        $limiter = $this->app->rateLimit();
        if ($limiter->tooManyAttempts('login')) {
            Response::fail(
                'Too many attempts. Try again in ' . ceil($limiter->retryAfter('login') / 60) . ' minute(s).',
                'rate_limited',
                429
            );
        }

        $username = $this->request->str('username');
        if (!$this->app->auth()->attempt($username, $this->request->str('password'))) {
            $limiter->hit('login');
            $this->app->logger()->audit($username, 'auth.failed');
            Response::fail('Incorrect username or password.', 'bad_credentials', 401);
        }
        $limiter->clear('login');
        $this->app->logger()->audit($username, 'auth.login');

        Response::ok([
            'csrf'  => Csrf::token(),
            'user'  => $this->app->auth()->username(),
            'sites' => $this->siteListArray(),
        ]);
    }

    private function authLogout(): never
    {
        $this->app->logger()->audit($this->app->auth()->username(), 'auth.logout');
        $this->app->auth()->logout();

        Response::ok(true);
    }

    private function authPassword(): never
    {
        $this->app->auth()->changePassword(
            $this->app->auth()->username(),
            $this->request->str('current'),
            $this->request->str('new')
        );
        $this->app->logger()->audit($this->app->auth()->username(), 'auth.password_changed');

        Response::ok(true);
    }

    // ------------------------------------------------------------------- sites

    private function siteList(): never
    {
        Response::ok($this->siteListArray());
    }

    private function siteSave(): never
    {
        $site = $this->app->sites()->save($this->request->arr('site'));
        $this->app->logger()->audit($this->app->auth()->username(), 'site.save', ['id' => $site->id, 'name' => $site->name]);

        Response::ok(['site' => $site->toClient(), 'sites' => $this->siteListArray()]);
    }

    private function siteDelete(): never
    {
        $id = $this->request->str('id');
        $this->app->sites()->delete($id);
        $this->app->logger()->audit($this->app->auth()->username(), 'site.delete', ['id' => $id]);

        Response::ok(['sites' => $this->siteListArray()]);
    }

    private function siteTest(): never
    {
        $input = $this->request->arr('site');
        // An existing profile can be tested without resending its secrets.
        if (($input['id'] ?? '') !== '' && ($input['password'] ?? '') === '' && ($input['privateKey'] ?? '') === '') {
            $stored = $this->app->sites()->find((string) $input['id']);
            if ($stored !== null) {
                $input = array_merge($stored->toArray(), array_filter(
                    $input,
                    static fn ($v): bool => $v !== '' && $v !== null
                ));
            }
        }
        $site   = Site::fromArray($input);
        $driver = (new DriverFactory($this->app))->make($site);

        $started = microtime(true);
        try {
            $driver->connect();
            $home = $driver->home();
            $list = $driver->list($home);
        } finally {
            // A failed test must still release the connection and any key
            // material the driver had to stage on disk.
            $driver->disconnect();
        }

        Response::ok([
            'message' => $driver->describe(),
            'home'    => $home,
            'items'   => count($list),
            'ms'      => (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    private function hostKeyForget(): never
    {
        $this->app->hostKeys()->forget($this->request->str('host'), $this->request->int('port', 22));

        Response::ok(true);
    }

    // ---------------------------------------------------------------- browsing

    private function fsList(): never
    {
        $siteId = $this->request->str('site');
        $driver = $this->driver($siteId);
        $path   = $this->request->str('path');
        $path   = $path === '' ? $driver->home() : $driver->realpath($path);

        $entries = [];
        foreach ($driver->list($path) as $entry) {
            $entries[] = $entry->toArray();
        }

        $roots = $driver instanceof LocalDriver ? $driver->roots() : [];

        Response::ok([
            'site'         => $siteId,
            'path'         => $path,
            'parent'       => Path::parent($path),
            'home'         => $driver->home(),
            'entries'      => $entries,
            'capabilities' => $driver->capabilities(),
            'description'  => $driver->describe(),
            'roots'        => $roots,
            'totals'       => [
                'files'   => count(array_filter($entries, static fn ($e): bool => !$e['isDir'])),
                'folders' => count(array_filter($entries, static fn ($e): bool => $e['isDir'])),
                'bytes'   => array_sum(array_column($entries, 'size')),
            ],
        ]);
    }

    private function fsMkdir(): never
    {
        $driver = $this->driver($this->request->str('site'));
        $name   = Path::safeName($this->request->str('name'));
        if ($name === '') {
            Response::fail('Enter a folder name.');
        }
        $path = Path::join($this->request->str('path'), $name);
        $driver->mkdir($path);
        $this->audit('fs.mkdir', ['path' => $path]);

        Response::ok(['path' => $path]);
    }

    private function fsRename(): never
    {
        $driver = $this->driver($this->request->str('site'));
        $from   = $this->request->str('path');
        $name   = Path::safeName($this->request->str('name'));
        if ($name === '') {
            Response::fail('Enter a name.');
        }
        $to = Path::join(Path::parent($from), $name);
        if ($to === $from) {
            Response::ok(['path' => $to]);
        }
        $driver->rename($from, $to);
        $this->audit('fs.rename', ['from' => $from, 'to' => $to]);

        Response::ok(['path' => $to]);
    }

    private function fsDelete(): never
    {
        $driver = $this->driver($this->request->str('site'));
        $failed = [];
        $done   = 0;
        foreach ($this->request->arr('paths') as $raw) {
            $path = Path::normalise((string) $raw);
            if ($path === '/') {
                $failed[] = ['path' => $path, 'error' => 'Refusing to delete the root directory.'];
                continue;
            }
            try {
                $entry = $driver->stat($path);
                $driver->delete($path, $entry !== null && $entry->isDir());
                $done++;
            } catch (Throwable $e) {
                $failed[] = ['path' => $path, 'error' => $e->getMessage()];
            }
        }
        $this->audit('fs.delete', ['deleted' => $done, 'failed' => count($failed)]);

        Response::ok(['deleted' => $done, 'failed' => $failed]);
    }

    private function fsChmod(): never
    {
        $driver = $this->driver($this->request->str('site'));
        $mode   = $this->request->str('mode');
        if (!preg_match('/^[0-7]{3,4}$/', $mode)) {
            Response::fail('Permissions must look like 755 or 0644.');
        }
        $octal   = (int) octdec($mode);
        $applied = 0;
        foreach ($this->request->arr('paths') as $raw) {
            $driver->chmod(Path::normalise((string) $raw), $octal);
            $applied++;
        }
        $this->audit('fs.chmod', ['mode' => $mode, 'count' => $applied]);

        Response::ok(['applied' => $applied]);
    }

    private function fsRead(): never
    {
        $driver = $this->driver($this->request->str('site'));
        $path   = $this->request->str('path');
        $entry  = $driver->stat($path);
        $limit  = (int) $this->app->config['max_edit_size'];
        if ($entry !== null && $entry->size > $limit) {
            Response::fail('File is larger than ' . Bytes::human($limit) . ' - download it instead.', 'too_large');
        }

        $handle  = $driver->readStream($path);
        $content = (string) stream_get_contents($handle, $limit + 1);
        fclose($handle);
        if (strlen($content) > $limit) {
            Response::fail('File is larger than ' . Bytes::human($limit) . ' - download it instead.', 'too_large');
        }

        Response::ok([
            'path'     => $path,
            'content'  => $content,
            'size'     => strlen($content),
            'language' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            'binary'   => !mb_check_encoding($content, 'UTF-8'),
        ]);
    }

    private function fsWrite(): never
    {
        $driver  = $this->driver($this->request->str('site'));
        $path    = $this->request->str('path');
        $content = $this->request->str('content');

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $content);
        rewind($stream);
        $driver->writeStream($path, $stream, strlen($content));
        fclose($stream);
        $this->audit('fs.write', ['path' => $path, 'bytes' => strlen($content)]);

        Response::ok(['path' => $path, 'size' => strlen($content)]);
    }

    /** Multipart upload; large files arrive as sequential chunks. */
    private function fsUpload(): never
    {
        $siteId = (string) ($_POST['site'] ?? '');
        $dir    = Path::normalise((string) ($_POST['path'] ?? '/'));
        $name   = Path::safeName((string) ($_POST['name'] ?? ''));
        if ($name === '' || !isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Response::fail('Upload failed before it reached the server.', 'upload_error');
        }

        $chunkIndex = (int) ($_POST['chunkIndex'] ?? 0);
        $chunkTotal = (int) ($_POST['chunkTotal'] ?? 1);
        $uploadId   = preg_replace('/[^a-z0-9]/i', '', (string) ($_POST['uploadId'] ?? '')) ?: bin2hex(random_bytes(6));
        $target     = Path::join($dir, $name);

        if ($chunkTotal <= 1) {
            $handle = fopen($_FILES['file']['tmp_name'], 'rb');
            $this->driver($siteId)->writeStream($target, $handle, (int) $_FILES['file']['size']);
            fclose($handle);
            $this->audit('fs.upload', ['path' => $target, 'bytes' => (int) $_FILES['file']['size']]);

            Response::ok(['path' => $target, 'complete' => true]);
        }

        $spool = $this->app->base . '/storage/tmp/up-' . $uploadId;
        $part  = fopen($_FILES['file']['tmp_name'], 'rb');
        $sink  = fopen($spool, $chunkIndex === 0 ? 'wb' : 'ab');
        stream_copy_to_stream($part, $sink);
        fclose($part);
        fclose($sink);

        if ($chunkIndex + 1 < $chunkTotal) {
            Response::ok(['uploadId' => $uploadId, 'complete' => false, 'received' => $chunkIndex + 1]);
        }

        $handle = fopen($spool, 'rb');
        $this->driver($siteId)->writeStream($target, $handle, (int) filesize($spool));
        fclose($handle);
        @unlink($spool);
        $this->audit('fs.upload', ['path' => $target, 'chunks' => $chunkTotal]);

        Response::ok(['path' => $target, 'complete' => true]);
    }

    // --------------------------------------------------------------- transfers

    private function transferEnqueue(): never
    {
        $job = $this->app->transfers()->enqueue($this->request->input, $this->app->auth()->username());

        Response::ok(['job' => $job->toClient()]);
    }

    private function transferList(): never
    {
        $store = $this->app->jobs();
        $store->prune();
        $jobs = array_map(
            static fn ($job): array => $job->toClient(),
            $store->all($this->app->auth()->username())
        );

        Response::ok(['jobs' => array_slice($jobs, 0, 50)]);
    }

    private function transferCancel(): never
    {
        $this->app->jobs()->cancel($this->request->str('id'));
        $this->audit('transfer.cancel', ['job' => $this->request->str('id')]);

        Response::ok(true);
    }

    private function transferRetry(): never
    {
        $old = $this->app->jobs()->getOrFail($this->request->str('id'));
        $job = $this->app->transfers()->enqueue([
            'sourceSite' => $old->sourceSite,
            'targetSite' => $old->targetSite,
            'paths'      => $old->sourcePaths,
            'targetPath' => $old->targetPath,
            'mode'       => $old->mode,
            'conflict'   => $old->conflict,
            'sourceBase' => $old->sourceBase,
        ], $this->app->auth()->username());

        Response::ok(['job' => $job->toClient()]);
    }

    private function transferClear(): never
    {
        Response::ok(['cleared' => $this->app->jobs()->clearFinished($this->app->auth()->username())]);
    }

    /** Fallback runner for hosts where proc_open is disabled. */
    private function transferRun(): never
    {
        $id = $this->request->str('id');
        ignore_user_abort(true);
        set_time_limit(0);
        $this->app->transfers()->run($id);

        Response::ok(true);
    }

    // ----------------------------------------------------------------- helpers

    private function driver(string $siteId): DriverInterface
    {
        if (!isset($this->drivers[$siteId])) {
            $site   = $this->app->sites()->findOrFail($siteId);
            $driver = (new DriverFactory($this->app))->connect($site);
            $this->drivers[$siteId] = $driver;

            // Response::json() ends the request with exit(), which skips finally
            // blocks - a shutdown hook is the only reliable place to clean up.
            if (!$this->shutdownRegistered) {
                $this->shutdownRegistered = true;
                register_shutdown_function(function (): void {
                    foreach ($this->drivers as $open) {
                        $open->disconnect();
                    }
                });
            }
        }

        return $this->drivers[$siteId];
    }

    private function siteListArray(): array
    {
        return array_values(array_map(
            static fn (Site $site): array => $site->toClient(),
            $this->app->sites()->all()
        ));
    }

    private function uploadLimit(): int
    {
        $configured = (int) $this->app->config['max_upload_size'];
        $ini        = min(
            Bytes::parse((string) ini_get('upload_max_filesize')),
            Bytes::parse((string) ini_get('post_max_size')) ?: PHP_INT_MAX
        );

        return $configured > 0 ? min($configured, $ini) : $ini;
    }

    private function audit(string $action, array $context): void
    {
        $this->app->logger()->audit($this->app->auth()->username(), $action, $context);
    }
}
