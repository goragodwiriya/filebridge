<?php

declare(strict_types=1);

namespace FileBridge\Transfer;

use FileBridge\App;
use FileBridge\Fs\DriverFactory;
use FileBridge\Fs\DriverInterface;
use FileBridge\Fs\ExecCapable;
use FileBridge\Fs\Path;
use FileBridge\Site\Site;
use FileBridge\Support\Bytes;
use RuntimeException;
use Throwable;

/** Queues transfers and, inside the worker process, actually performs them. */
final class TransferManager
{
    /** @var null|callable(Job):void progress sink; null means the job file */
    private $sink = null;

    private float $lastFlush = 0.0;
    private float $windowStart = 0.0;
    private int $windowBytes = 0;
    private bool $cancelled = false;

    public function __construct(private readonly App $app)
    {
    }

    // ---------------------------------------------------------------- queueing

    public function enqueue(array $input, string $user): Job
    {
        $sites  = $this->app->sites();
        $source = $sites->findOrFail((string) ($input['sourceSite'] ?? ''));
        $target = $sites->findOrFail((string) ($input['targetSite'] ?? ''));

        $paths = array_values(array_filter(array_map(
            static fn ($p): string => Path::normalise((string) $p),
            (array) ($input['paths'] ?? [])
        )));
        if ($paths === []) {
            throw new RuntimeException('Nothing was selected to transfer.');
        }

        $targetPath = Path::normalise((string) ($input['targetPath'] ?? '/'));
        $mode       = ($input['mode'] ?? 'copy') === 'move' ? 'move' : 'copy';

        if ($source->id === $target->id) {
            $conflict = (string) ($input['conflict'] ?? 'overwrite');
            foreach ($paths as $path) {
                // A directory copied inside itself would recurse forever.
                if (Path::contains($path, $targetPath)) {
                    throw new RuntimeException('Cannot transfer "' . Path::name($path) . '" into itself.');
                }
                // Same folder means every file would be written onto its own
                // source. Only "keep both" makes sense there - it duplicates.
                if (Path::parent($path) === $targetPath && $conflict !== 'rename') {
                    throw new RuntimeException(
                        'Source and destination are the same folder. Choose "Keep both" to duplicate instead.'
                    );
                }
            }
        }

        $job = new Job(
            user: $user,
            mode: $mode,
            conflict: in_array($input['conflict'] ?? '', ['overwrite', 'skip', 'rename', 'newer'], true)
                ? (string) $input['conflict']
                : 'overwrite',
            sourceSite: $source->id,
            sourceSiteName: $source->name,
            sourcePaths: $paths,
            sourceBase: Path::normalise((string) ($input['sourceBase'] ?? Path::parent($paths[0]))),
            targetSite: $target->id,
            targetSiteName: $target->name,
            targetPath: $targetPath
        );

        $job = $this->app->jobs()->create($job);
        $this->app->logger()->audit($user, 'transfer.enqueue', [
            'job' => $job->id, 'mode' => $mode,
            'from' => $source->name, 'to' => $target->name,
            'paths' => $paths, 'dest' => $targetPath,
        ]);
        $job->spawned = $this->spawn($job->id);
        $this->app->jobs()->put($job);

        return $job;
    }

    /** Detach a worker process. Returns false when the platform forbids it. */
    public function spawn(string $jobId): bool
    {
        $php = $this->app->phpBinary();
        if ($php === null || !function_exists('proc_open')) {
            return false;
        }
        $cmd = sprintf(
            '%s %s %s > /dev/null 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($this->app->base . '/worker.php'),
            escapeshellarg($jobId)
        );
        $handle = @proc_open(['/bin/sh', '-c', $cmd], [], $pipes);
        if (!is_resource($handle)) {
            return false;
        }
        proc_close($handle);

        return true;
    }

    // ----------------------------------------------------------------- running

    public function run(string $jobId): void
    {
        $store = $this->app->jobs();
        $job   = $store->get($jobId);
        if ($job === null || $job->status !== Job::QUEUED) {
            return;
        }

        $job->status    = Job::SCANNING;
        $job->startedAt = time();
        $store->put($job);

        $factory = new DriverFactory($this->app);
        $src = $dst = null;

        try {
            $sourceSite = $this->app->sites()->findOrFail($job->sourceSite);
            $targetSite = $this->app->sites()->findOrFail($job->targetSite);
            $src = $factory->connect($sourceSite);
            $dst = $job->sourceSite === $job->targetSite ? $src : $factory->connect($targetSite);

            if ($this->tryServerSide($job, $src, $sourceSite, $targetSite)) {
                $job->status     = Job::DONE;
                $job->finishedAt = time();
                $store->put($job);

                return;
            }

            $plan = $this->buildPlan($job, $src, $store);
            if ($this->cancelled) {
                $this->finishCancelled($job, $store);

                return;
            }

            $job->status = Job::RUNNING;
            $store->put($job);

            // Directories first, in one process: shards must never race here.
            $this->prepareDirs($job, $plan, $dst);

            $workers = $this->workerCount(count($plan['files']));
            if ($workers < 2 || !$this->runParallel($job, $plan['files'], $src, $dst, $store, $workers)) {
                $this->execute($job, $plan['files'], $src, $dst, $store);
            }

            if ($this->cancelled) {
                $this->finishCancelled($job, $store);

                return;
            }

            if ($job->mode === 'move') {
                $this->removeSources($job, $src);
            }

            $job->status     = Job::DONE;
            $job->current    = '';
            $job->finishedAt = time();
            $job->note(sprintf(
                'Finished: %d file(s), %s transferred%s.',
                $job->filesDone,
                Bytes::human($job->bytesDone),
                $job->filesSkipped > 0 ? ', ' . $job->filesSkipped . ' skipped' : ''
            ));
            $store->put($job);
            $this->app->logger()->audit($job->user, 'transfer.done', [
                'job' => $job->id, 'files' => $job->filesDone, 'bytes' => $job->bytesDone,
            ]);
        } catch (Throwable $e) {
            $job->status     = $this->cancelled ? Job::CANCELLED : Job::ERROR;
            $job->error      = $e->getMessage();
            $job->finishedAt = time();
            $job->note($e->getMessage(), 'error');
            $store->put($job);
            $this->app->logger()->error('transfer failed', ['job' => $job->id, 'error' => $e->getMessage()]);
        } finally {
            $src?->disconnect();
            if ($dst !== null && $dst !== $src) {
                $dst->disconnect();
            }
            $store->clearCancel($jobId);
        }
    }

    /**
     * Same host on both sides: let the server copy the bytes itself instead of
     * pulling them through this machine.
     */
    private function tryServerSide(Job $job, DriverInterface $driver, Site $source, Site $target): bool
    {
        // cp/mv always overwrite, so any other conflict policy has to stream.
        if ($job->sourceSite !== $job->targetSite || $job->conflict !== 'overwrite') {
            return false;
        }
        if (!$driver instanceof ExecCapable || !$driver->capabilities()['exec']) {
            return false;
        }

        $command = $job->mode === 'move' ? 'mv -f' : 'cp -a';
        $parts   = [];
        foreach ($job->sourcePaths as $path) {
            $parts[] = escapeshellarg($path);
        }
        $script = sprintf(
            'mkdir -p %s && %s %s %s && echo FB_OK',
            escapeshellarg($job->targetPath),
            $command,
            implode(' ', $parts),
            escapeshellarg(rtrim($job->targetPath, '/') . '/')
        );

        $job->note('Same host on both sides - copying server-side.');
        $out = $driver->exec($script);
        if (!str_contains($out, 'FB_OK')) {
            $job->note('Server-side copy unavailable, streaming instead.');

            return false;
        }

        $job->filesDone  = count($job->sourcePaths);
        $job->filesTotal = $job->filesDone;
        $job->note('Server-side ' . ($job->mode === 'move' ? 'move' : 'copy') . ' completed.');

        return true;
    }

    /**
     * Walk the selection into a flat list of directories to create and files to
     * copy, so the progress bar has a real total to work against.
     *
     * @return array{dirs:string[],files:array<int,array{src:string,dst:string,size:int,mtime:int}>}
     */
    private function buildPlan(Job $job, DriverInterface $src, JobStore $store): array
    {
        $plan = ['dirs' => [], 'files' => []];

        foreach ($job->sourcePaths as $path) {
            if ($this->checkCancelled($job)) {
                return $plan;
            }
            $entry = $src->stat($path);
            if ($entry === null) {
                $job->note('Skipped (not found): ' . $path, 'warn');
                continue;
            }
            $destination = Path::join($job->targetPath, $entry->name);
            if ($entry->isDir()) {
                $plan['dirs'][] = $destination;
                $this->walk($job, $src, $path, $destination, $plan, $store);
            } else {
                $plan['files'][] = [
                    'src'   => $path,
                    'dst'   => $destination,
                    'size'  => $entry->size,
                    'mtime' => $entry->mtime,
                ];
            }
        }

        $job->filesTotal = count($plan['files']);
        $job->bytesTotal = array_sum(array_column($plan['files'], 'size'));
        $job->note(sprintf('Planned %d file(s), %s.', $job->filesTotal, Bytes::human($job->bytesTotal)));
        $store->put($job);

        return $plan;
    }

    private function walk(Job $job, DriverInterface $src, string $dir, string $destDir, array &$plan, JobStore $store): void
    {
        foreach ($src->list($dir) as $entry) {
            if ($this->checkCancelled($job)) {
                return;
            }
            $destination = Path::join($destDir, $entry->name);
            if ($entry->isDir()) {
                $plan['dirs'][] = $destination;
                $this->walk($job, $src, $entry->path, $destination, $plan, $store);
                continue;
            }
            $plan['files'][] = [
                'src'   => $entry->path,
                'dst'   => $destination,
                'size'  => $entry->size,
                'mtime' => $entry->mtime,
            ];
            if (count($plan['files']) % 250 === 0) {
                $job->filesTotal = count($plan['files']);
                $job->bytesTotal = array_sum(array_column($plan['files'], 'size'));
                $store->put($job);
            }
        }
    }

    private function prepareDirs(Job $job, array $plan, DriverInterface $dst): void
    {
        foreach ($plan['dirs'] as $dir) {
            $dst->mkdir($dir);
        }
        if ($plan['dirs'] === [] && $plan['files'] !== []) {
            $dst->mkdir($job->targetPath);
        }
    }

    /** @param array<int,array{src:string,dst:string,size:int,mtime:int}> $files */
    private function execute(Job $job, array $files, DriverInterface $src, DriverInterface $dst, JobStore $store): void
    {
        $this->windowStart = microtime(true);
        $this->windowBytes = 0;

        foreach ($files as $file) {
            if ($this->checkCancelled($job)) {
                return;
            }

            $destination = $this->resolveConflict($job, $dst, $file);
            // Never open a file for writing while reading from the same path.
            if ($destination === $file['src'] && $job->sourceSite === $job->targetSite) {
                $job->note('Skipped (source and destination are the same file): ' . $file['src'], 'warn');
                $destination = null;
            }
            if ($destination === null) {
                $job->filesSkipped++;
                $job->filesDone++;
                $job->bytesDone += $file['size'];
                $this->save($job, $store);
                continue;
            }

            $job->current      = $file['src'];
            $job->currentTotal = $file['size'];
            $job->currentBytes = 0;
            $this->flush($job, $store, true);

            $baseline = $job->bytesDone;
            $handle   = $src->readStream($file['src']);
            ProgressFilter::attach($handle, function (int $bytes) use ($job, $store, $baseline): bool {
                $job->currentBytes += $bytes;
                $job->bytesDone     = $baseline + $job->currentBytes;
                $this->windowBytes += $bytes;
                $this->flush($job, $store);

                return !$this->checkCancelled($job);
            });

            try {
                $dst->writeStream($destination, $handle, $file['size']);
            } finally {
                is_resource($handle) && fclose($handle);
            }

            if ($this->cancelled) {
                return;
            }

            $job->filesDone++;
            $job->bytesDone = $baseline + max($file['size'], $job->currentBytes);
            $this->flush($job, $store, true);
        }
    }

    // -------------------------------------------------------- parallel workers

    /**
     * Hand the file list to a pool of worker processes.
     *
     * One file at a time is fine on a LAN, but over a long link every file costs
     * a fixed round trip that no chunk size can remove - the only way to hide it
     * is to keep several files in flight. Returns false when the pool cannot be
     * started, so the caller can still copy in this process.
     *
     * @param array<int,array{src:string,dst:string,size:int,mtime:int}> $files
     */
    private function runParallel(
        Job $job,
        array $files,
        DriverInterface $src,
        DriverInterface $dst,
        JobStore $store,
        int $workers
    ): bool {
        $buckets = $this->shareOut($files, $workers);
        $workers = count($buckets);
        $store->putPlan($job->id, $buckets);

        $procs = [];
        for ($i = 0; $i < $workers; $i++) {
            $handle = $this->spawnShard($job->id, $i, $workers);
            if ($handle === null) {
                foreach ($procs as $started) {
                    @proc_terminate($started);
                    @proc_close($started);
                }
                $store->clearParts($job->id);
                $job->note('Could not start the worker pool - copying in this process instead.', 'warn');

                return false;
            }
            $procs[] = $handle;
        }

        // The shards hold their own connections now; ours would only idle out.
        $src->disconnect();
        $dst->disconnect();

        $job->note(sprintf('Copying %d file(s) across %d workers.', count($files), $workers));
        $store->put($job);

        $this->supervise($job, $store, $procs, $workers);

        return true;
    }

    /** One slice of a job: copies its own bucket, reports into its own file. */
    public function runShard(string $jobId, int $index, int $count): void
    {
        $store = $this->app->jobs();
        $job   = $store->getOrFail($jobId);
        $files = $store->plan($jobId)[$index] ?? [];
        if ($files === []) {
            $store->putPart($jobId, $index, ['status' => 'done']);

            return;
        }

        // Counters are per shard from here; the coordinator adds them up.
        $job->filesDone    = 0;
        $job->filesSkipped = 0;
        $job->bytesDone    = 0;
        $job->log          = [];
        $this->sink = static function (Job $shard) use ($store, $jobId, $index): void {
            $store->putPart($jobId, $index, ['status' => 'running'] + $shard->toArray());
        };

        $factory = new DriverFactory($this->app);
        $src = $dst = null;

        try {
            $src = $factory->connect($this->app->sites()->findOrFail($job->sourceSite), true);
            $dst = $job->sourceSite === $job->targetSite
                ? $src
                : $factory->connect($this->app->sites()->findOrFail($job->targetSite), true);

            $this->execute($job, $files, $src, $dst, $store);

            $store->putPart($jobId, $index, [
                'status' => $this->cancelled ? 'cancelled' : 'done',
            ] + $job->toArray());
        } catch (Throwable $e) {
            $store->putPart($jobId, $index, [
                'status' => 'error',
                'error'  => $e->getMessage(),
            ] + $job->toArray());
            $this->app->logger()->error('transfer shard failed', [
                'job' => $jobId, 'shard' => $index, 'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $src?->disconnect();
            if ($dst !== null && $dst !== $src) {
                $dst->disconnect();
            }
        }
    }

    /** @param array<int,resource> $procs */
    private function supervise(Job $job, JobStore $store, array $procs, int $workers): void
    {
        $interval = max(0.1, (float) $this->app->config['progress_interval']);
        $exits    = [];

        while (true) {
            $running = false;
            foreach ($procs as $i => $proc) {
                $status = proc_get_status($proc);
                if ($status['running']) {
                    $running = true;
                } elseif (!array_key_exists($i, $exits)) {
                    // Only the first look after exit carries the real code.
                    $exits[$i] = (int) $status['exitcode'];
                }
            }
            $this->collect($job, $store, $workers);
            $this->checkCancelled($job); // the shards watch the same marker
            if (!$running) {
                break;
            }
            usleep((int) ($interval * 1000000));
        }

        foreach ($procs as $proc) {
            @proc_close($proc);
        }
        $this->collect($job, $store, $workers, true);

        $failure = null;
        for ($i = 0; $i < $workers; $i++) {
            $part = $store->part($job->id, $i);
            if ((string) ($part['error'] ?? '') !== '') {
                $failure = (string) $part['error'];
                break;
            }
            if (($exits[$i] ?? 0) !== 0 && ($part['status'] ?? '') !== 'cancelled') {
                $failure = sprintf('Worker %d stopped unexpectedly (exit code %d).', $i + 1, $exits[$i] ?? -1);
                break;
            }
        }
        $store->clearParts($job->id);

        if ($failure !== null && !$this->cancelled) {
            throw new RuntimeException($failure);
        }
    }

    /** Add the shards' counters up into the job the UI polls. */
    private function collect(Job $job, JobStore $store, int $workers, bool $final = false): void
    {
        $files = $bytes = $skipped = 0;
        $speed = 0.0;
        $job->current      = '';
        $job->currentBytes = 0;
        $job->currentTotal = 0;

        for ($i = 0; $i < $workers; $i++) {
            $part = $store->part($job->id, $i);
            if ($part === null) {
                continue;
            }
            $files   += (int) ($part['filesDone'] ?? 0);
            $bytes   += (int) ($part['bytesDone'] ?? 0);
            $skipped += (int) ($part['filesSkipped'] ?? 0);
            $speed   += (float) ($part['speed'] ?? 0);
            if ($job->current === '' && (string) ($part['current'] ?? '') !== '') {
                $job->current      = (string) $part['current'];
                $job->currentBytes = (int) ($part['currentBytes'] ?? 0);
                $job->currentTotal = (int) ($part['currentTotal'] ?? 0);
            }
            if ($final) {
                foreach ((array) ($part['log'] ?? []) as $entry) {
                    // Only the shards' warnings are worth repeating in the job log.
                    if (is_array($entry) && (string) ($entry['level'] ?? 'info') !== 'info') {
                        $job->note((string) ($entry['message'] ?? ''), (string) $entry['level']);
                    }
                }
            }
        }

        $job->filesDone    = $files;
        $job->bytesDone    = $bytes;
        $job->filesSkipped = $skipped;
        $job->speed        = $speed;
        $job->eta          = $speed > 1 ? (int) round(max(0, $job->bytesTotal - $bytes) / $speed) : 0;
        $store->put($job);
    }

    /**
     * Deal the files out heaviest first, always onto the lightest worker. Empty
     * files still cost a round trip, so nobody is handed a free ride.
     *
     * Files aiming at the same destination stay together: "keep both" picks the
     * next free name by looking at the target, and two workers looking at once
     * would settle on the very same name.
     *
     * @param  array<int,array{src:string,dst:string,size:int,mtime:int}> $files
     * @return array<int,array<int,array{src:string,dst:string,size:int,mtime:int}>>
     */
    private function shareOut(array $files, int $workers): array
    {
        $groups = [];
        $weight = [];
        foreach ($files as $file) {
            $groups[$file['dst']][] = $file;
            $weight[$file['dst']] = ($weight[$file['dst']] ?? 0) + max(65536, (int) $file['size']);
        }
        arsort($weight);

        $buckets = array_fill(0, $workers, []);
        $load    = array_fill(0, $workers, 0);
        foreach (array_keys($weight) as $destination) {
            $lightest = (int) array_search(min($load), $load, true);
            foreach ($groups[$destination] as $file) {
                $buckets[$lightest][] = $file;
            }
            $load[$lightest] += $weight[$destination];
        }

        return array_values(array_filter($buckets, static fn (array $bucket): bool => $bucket !== []));
    }

    /** @return resource|null */
    private function spawnShard(string $jobId, int $index, int $count)
    {
        $php = $this->app->phpBinary();
        if ($php === null || !function_exists('proc_open')) {
            return null;
        }
        // A real process handle, unlike spawn(): the coordinator needs the exit code.
        $handle = @proc_open(
            [$php, $this->app->base . '/worker.php', $jobId, (string) $index, (string) $count],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', $this->app->base . '/storage/logs/worker.log', 'a'],
            ],
            $pipes
        );

        return is_resource($handle) ? $handle : null;
    }

    private function workerCount(int $files): int
    {
        $configured = (int) ($this->app->config['transfer_workers'] ?? 1);
        if ($configured < 2 || $files < 2 || !function_exists('proc_open') || $this->app->phpBinary() === null) {
            return 1;
        }

        return min($configured, $files, 16);
    }

    /** @return string|null destination path, or null to skip this file */
    private function resolveConflict(Job $job, DriverInterface $dst, array $file): ?string
    {
        if ($job->conflict === 'overwrite') {
            return $file['dst'];
        }
        $existing = $dst->stat($file['dst']);
        if ($existing === null) {
            return $file['dst'];
        }

        return match ($job->conflict) {
            'skip'   => null,
            'newer'  => $file['mtime'] > $existing->mtime ? $file['dst'] : null,
            'rename' => $this->freeName($dst, $file['dst']),
            default  => $file['dst'],
        };
    }

    private function freeName(DriverInterface $dst, string $path): string
    {
        $dir  = Path::parent($path);
        $name = Path::name($path);
        for ($i = 1; $i < 1000; $i++) {
            $candidate = Path::join($dir, Path::withSuffix($name, ' (' . $i . ')'));
            if ($dst->stat($candidate) === null) {
                return $candidate;
            }
        }

        return Path::join($dir, Path::withSuffix($name, '-' . time()));
    }

    private function removeSources(Job $job, DriverInterface $src): void
    {
        foreach ($job->sourcePaths as $path) {
            try {
                $entry = $src->stat($path);
                $src->delete($path, $entry !== null && $entry->isDir());
            } catch (Throwable $e) {
                $job->note('Could not remove source ' . $path . ': ' . $e->getMessage(), 'warn');
            }
        }
    }

    private function finishCancelled(Job $job, JobStore $store): void
    {
        $job->status     = Job::CANCELLED;
        $job->current    = '';
        $job->finishedAt = time();
        $job->note('Cancelled by the operator.', 'warn');
        $store->put($job);
    }

    private function checkCancelled(Job $job): bool
    {
        if ($this->cancelled) {
            return true;
        }
        $this->cancelled = $this->app->jobs()->isCancelled($job->id);

        return $this->cancelled;
    }

    /** Throttled progress write plus a rolling speed estimate. */
    private function flush(Job $job, JobStore $store, bool $force = false): void
    {
        $now      = microtime(true);
        $interval = (float) $this->app->config['progress_interval'];
        if (!$force && $now - $this->lastFlush < $interval) {
            return;
        }

        $elapsed = $now - $this->windowStart;
        if ($elapsed >= 1.0) {
            $job->speed        = $this->windowBytes / $elapsed;
            $this->windowStart = $now;
            $this->windowBytes = 0;
        }
        $remaining  = max(0, $job->bytesTotal - $job->bytesDone);
        $job->eta   = $job->speed > 1 ? (int) round($remaining / $job->speed) : 0;
        $this->lastFlush = $now;
        $this->save($job, $store);
    }

    private function save(Job $job, JobStore $store): void
    {
        if ($this->sink !== null) {
            ($this->sink)($job);

            return;
        }
        $store->put($job);
    }
}
