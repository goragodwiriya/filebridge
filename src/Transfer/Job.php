<?php

declare(strict_types=1);

namespace FileBridge\Transfer;

/** A queued transfer. Serialised to one JSON file so the worker and the web
 *  request can see the same state without a database. */
final class Job
{
    public const QUEUED    = 'queued';
    public const SCANNING  = 'scanning';
    public const RUNNING   = 'running';
    public const DONE      = 'done';
    public const ERROR     = 'error';
    public const CANCELLED = 'cancelled';

    public function __construct(
        public string $id = '',
        public string $user = '',
        public string $mode = 'copy',           // copy | move
        public string $conflict = 'overwrite',  // overwrite | skip | rename | newer
        public string $sourceSite = '',
        public string $sourceSiteName = '',
        /** @var string[] */
        public array $sourcePaths = [],
        public string $sourceBase = '/',
        public string $targetSite = '',
        public string $targetSiteName = '',
        public string $targetPath = '/',
        public string $status = self::QUEUED,
        public int $filesTotal = 0,
        public int $filesDone = 0,
        public int $filesSkipped = 0,
        public int $bytesTotal = 0,
        public int $bytesDone = 0,
        public string $current = '',
        public int $currentBytes = 0,
        public int $currentTotal = 0,
        public float $speed = 0.0,
        public int $eta = 0,
        public string $error = '',
        /** @var array<int,array{time:int,level:string,message:string}> */
        public array $log = [],
        public bool $spawned = false,
        public int $createdAt = 0,
        public int $startedAt = 0,
        public int $finishedAt = 0
    ) {
    }

    public static function fromArray(array $d): self
    {
        $job = new self();
        foreach ($d as $key => $value) {
            if (property_exists($job, $key)) {
                $job->{$key} = $value;
            }
        }

        return $job;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function finished(): bool
    {
        return in_array($this->status, [self::DONE, self::ERROR, self::CANCELLED], true);
    }

    public function percent(): float
    {
        if ($this->bytesTotal > 0) {
            return min(100.0, round($this->bytesDone / $this->bytesTotal * 100, 1));
        }

        return $this->filesTotal > 0 ? round($this->filesDone / $this->filesTotal * 100, 1) : 0.0;
    }

    public function note(string $message, string $level = 'info'): void
    {
        $this->log[] = ['time' => time(), 'level' => $level, 'message' => $message];
        if (count($this->log) > 200) {
            array_splice($this->log, 0, count($this->log) - 200);
        }
    }

    public function toClient(): array
    {
        $data            = $this->toArray();
        $data['percent'] = $this->percent();
        $data['title']   = count($this->sourcePaths) === 1
            ? basename($this->sourcePaths[0])
            : count($this->sourcePaths) . ' items';

        return $data;
    }
}
