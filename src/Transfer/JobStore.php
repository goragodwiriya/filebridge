<?php

declare(strict_types=1);

namespace FileBridge\Transfer;

use FileBridge\Support\Lang;
use RuntimeException;

/** File-backed job queue. One JSON document per job plus a marker file for cancellation. */
final class JobStore
{
    public function __construct(
        private readonly string $dir,
        private readonly int $retention = 86400
    ) {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }
    }

    public function create(Job $job): Job
    {
        $job->id        = $job->id !== '' ? $job->id : bin2hex(random_bytes(8));
        $job->createdAt = time();
        $this->put($job);

        return $job;
    }

    public function get(string $id): ?Job
    {
        $file = $this->file($id);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? Job::fromArray($data) : null;
    }

    public function getOrFail(string $id): Job
    {
        return $this->get($id) ?? throw new RuntimeException(Lang::t('err.unknown_job', ['id' => $id]));
    }

    public function put(Job $job): void
    {
        $file = $this->file($job->id);
        $tmp  = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $this->encode($job->toArray())) === false) {
            throw new RuntimeException(Lang::t('err.write_job', ['file' => $file]));
        }
        @rename($tmp, $file); // atomic: readers never see a half written document
    }

    /** @return Job[] newest first */
    public function all(?string $user = null): array
    {
        $jobs = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }
            $job = Job::fromArray($data);
            if ($user !== null && $job->user !== $user) {
                continue;
            }
            $jobs[] = $job;
        }
        usort($jobs, static fn (Job $a, Job $b): int => $b->createdAt <=> $a->createdAt);

        return $jobs;
    }

    public function cancel(string $id): void
    {
        @touch($this->dir . '/' . $this->safe($id) . '.cancel');
    }

    public function isCancelled(string $id): bool
    {
        return is_file($this->dir . '/' . $this->safe($id) . '.cancel');
    }

    public function clearCancel(string $id): void
    {
        @unlink($this->dir . '/' . $this->safe($id) . '.cancel');
    }

    public function delete(string $id): void
    {
        @unlink($this->file($id));
        $this->clearCancel($id);
        $this->clearParts($id);
    }

    // ------------------------------------------------- parallel worker scratch

    /**
     * The file list each shard worker is responsible for.
     *
     * @param array<int,array<int,array{src:string,dst:string,size:int,mtime:int}>> $buckets
     */
    public function putPlan(string $id, array $buckets): void
    {
        $file = $this->scratch($id, 'plan');
        if (@file_put_contents($file, $this->encode($buckets)) === false) {
            throw new RuntimeException(Lang::t('err.write_plan', ['file' => $file]));
        }
    }

    /** @return array<int,array<int,array{src:string,dst:string,size:int,mtime:int}>> */
    public function plan(string $id): array
    {
        $data = json_decode((string) @file_get_contents($this->scratch($id, 'plan')), true);

        return is_array($data) ? $data : [];
    }

    /** One shard's progress. Written by the shard, read by the coordinator. */
    public function putPart(string $id, int $index, array $data): void
    {
        $file = $this->scratch($id, 'p' . $index);
        $tmp  = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $this->encode($data)) !== false) {
            @rename($tmp, $file);
        }
    }

    public function part(string $id, int $index): ?array
    {
        $data = json_decode((string) @file_get_contents($this->scratch($id, 'p' . $index)), true);

        return is_array($data) ? $data : null;
    }

    public function clearParts(string $id): void
    {
        foreach (glob($this->dir . '/' . $this->safe($id) . '.*.part*') ?: [] as $file) {
            @unlink($file);
        }
    }

    /** Never *.json: all() globs that pattern and must only ever see real jobs. */
    private function scratch(string $id, string $kind): string
    {
        return $this->dir . '/' . $this->safe($id) . '.' . $kind . '.part';
    }

    private function encode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Remove finished jobs older than the retention window. */
    public function prune(): void
    {
        $cutoff = time() - $this->retention;
        foreach ($this->all() as $job) {
            if ($job->finished() && $job->createdAt < $cutoff) {
                $this->delete($job->id);
            }
        }
    }

    /** Drop every finished job for a user (the "clear" button). */
    public function clearFinished(string $user): int
    {
        $count = 0;
        foreach ($this->all($user) as $job) {
            if ($job->finished()) {
                $this->delete($job->id);
                $count++;
            }
        }

        return $count;
    }

    private function file(string $id): string
    {
        return $this->dir . '/' . $this->safe($id) . '.json';
    }

    private function safe(string $id): string
    {
        return preg_replace('/[^a-f0-9]/i', '', $id) ?: 'invalid';
    }
}
