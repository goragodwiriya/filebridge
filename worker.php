<?php

declare(strict_types=1);

/**
 * Background transfer runner.
 *
 *   php worker.php <jobId>                     coordinator
 *   php worker.php <jobId> <shard> <shards>    one slice of a parallel job
 *
 * Started detached by TransferManager::spawn(). The coordinator scans the
 * selection, creates the directories, then either copies the files itself or
 * spreads them over a pool of shard workers. Progress lands in
 * storage/jobs/<id>.json, which the UI polls. Safe to run by hand for debugging.
 */

use FileBridge\App;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("worker.php runs on the command line only.\n");
}

require __DIR__ . '/vendor/autoload.php';

$jobId = $argv[1] ?? '';
if ($jobId === '') {
    fwrite(STDERR, "Usage: php worker.php <jobId> [shard shards] | --prune\n");
    exit(1);
}

if ($jobId === '--prune') {
    App::boot(__DIR__)->jobs()->prune();
    fwrite(STDOUT, "pruned finished jobs past their retention window\n");
    exit(0);
}

@ini_set('memory_limit', '256M');
set_time_limit(0);
ignore_user_abort(true);

$app = App::boot(__DIR__);

// A shard copies its own slice of the plan and reports into its own part file;
// the coordinator that spawned it does the aggregating.
if (isset($argv[3]) && (int) $argv[3] > 1) {
    try {
        $app->transfers()->runShard($jobId, (int) $argv[2], (int) $argv[3]);
    } catch (Throwable $e) {
        fwrite(STDERR, sprintf("job %s shard %s failed: %s\n", $jobId, $argv[2], $e->getMessage()));
        exit(1);
    }
    exit(0);
}

$app->transfers()->run($jobId);

$job = $app->jobs()->get($jobId);
if ($job !== null) {
    fwrite(STDOUT, sprintf(
        "job %s finished with status %s (%d/%d files)\n",
        $job->id,
        $job->status,
        $job->filesDone,
        $job->filesTotal
    ));
    exit($job->status === 'error' ? 1 : 0);
}
exit(1);
