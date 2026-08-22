<?php

declare(strict_types=1);

/**
 * Streams one file - or a zip of a selection - straight to the browser.
 * Separate from api.php because the response is binary, not JSON.
 *
 * With ?inline=1 the file is displayed instead of saved, which the image
 * viewer uses. That path is deliberately narrow: only the types in
 * Mime::inlineType() may be inlined, and the response is locked down so the
 * browser cannot be talked into treating someone's upload as a document on
 * this origin.
 */

use FileBridge\App;
use FileBridge\Fs\DriverFactory;
use FileBridge\Fs\Path;
use FileBridge\Security\Csrf;
use FileBridge\Support\Lang;
use FileBridge\Support\Mime;

require __DIR__ . '/vendor/autoload.php';

$app = App::boot(__DIR__);
$app->startSession();

function fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}

if (!$app->ipAllowed()) {
    fail(403, Lang::t('err.download_denied'));
}
if (!$app->auth()->check()) {
    fail(401, Lang::t('err.download_signin'));
}
if (!Csrf::check((string) ($_GET['token'] ?? ''))) {
    fail(419, Lang::t('err.csrf'));
}

$siteId = (string) ($_GET['site'] ?? '');
$paths  = array_values(array_filter(array_map(
    static fn ($p): string => Path::normalise((string) $p),
    (array) ($_GET['path'] ?? [])
)));
if ($paths === []) {
    fail(400, Lang::t('err.download_empty'));
}

$site   = $app->sites()->findOrFail($siteId);
$driver = (new DriverFactory($app))->connect($site);

set_time_limit(0);
while (ob_get_level() > 0) {
    ob_end_clean();
}

$single = count($paths) === 1 ? $driver->stat($paths[0]) : null;
$inline = ($_GET['inline'] ?? '') !== '' && $single !== null && Mime::inlineType($single->name) !== null;

if ($single !== null && !$single->isDir()) {
    $app->logger()->audit(
        $app->auth()->username(),
        $inline ? 'fs.preview' : 'fs.download',
        ['site' => $siteId, 'path' => $paths[0]]
    );

    header('Content-Type: ' . ($inline ? Mime::inlineType($single->name) : Mime::contentType($single->name)));
    header('Content-Length: ' . $single->size);
    header(
        'Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
        . '; filename="' . addslashes($single->name) . '"; filename*=UTF-8\'\'' . rawurlencode($single->name)
    );
    header('X-Content-Type-Options: nosniff');
    if ($inline) {
        // Nothing served here may load anything, run anything, or be framed -
        // an SVG opened directly in a tab would otherwise run its own script
        // with this app's cookies.
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");
        header('X-Frame-Options: SAMEORIGIN');
        // The viewer puts the file's mtime in the URL, so a rewritten file
        // never comes back from the cache.
        header('Cache-Control: private, max-age=300');
    } else {
        header('Cache-Control: no-store');
    }

    $handle = $driver->readStream($paths[0]);
    $out    = fopen('php://output', 'wb');
    stream_copy_to_stream($handle, $out);
    fclose($handle);
    $driver->disconnect();
    exit;
}

// Folder or multi-selection: build a zip in the spool directory, then stream it.
$zipPath = $app->base . '/storage/tmp/dl-' . bin2hex(random_bytes(6)) . '.zip';
$zip     = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fail(500, Lang::t('err.download_zip'));
}

/** Recursively add a remote path into the archive. */
$addPath = function (string $path, string $inZip) use (&$addPath, $driver, $zip): void {
    $entry = $driver->stat($path);
    if ($entry === null) {
        return;
    }
    if (!$entry->isDir()) {
        $handle = $driver->readStream($path);
        $zip->addFromString($inZip, (string) stream_get_contents($handle));
        fclose($handle);

        return;
    }
    $zip->addEmptyDir($inZip);
    foreach ($driver->list($path) as $child) {
        $addPath($child->path, $inZip . '/' . $child->name);
    }
};

foreach ($paths as $path) {
    $addPath($path, Path::name($path));
}
$zip->close();

$name = count($paths) === 1 ? Path::name($paths[0]) . '.zip' : 'filebridge-' . date('Ymd-His') . '.zip';
$app->logger()->audit($app->auth()->username(), 'fs.download.zip', ['site' => $siteId, 'paths' => $paths]);

header('Content-Type: application/zip');
header('Content-Length: ' . (string) filesize($zipPath));
header('Content-Disposition: attachment; filename="' . addslashes($name) . '"');
header('Cache-Control: no-store');
readfile($zipPath);
@unlink($zipPath);
$driver->disconnect();
