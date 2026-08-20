<?php

declare(strict_types=1);

namespace FileBridge\Fs;

use FileBridge\Support\Mime;

/** One row in a directory listing, protocol independent. */
final class Entry
{
    public function __construct(
        public string $name,
        public string $path,
        public string $type = 'file',      // file | dir | link
        public int $size = 0,
        public int $mtime = 0,
        public ?int $perms = null,         // 0644 style, null when unknown
        public string $owner = '',
        public string $group = '',
        public ?string $target = null,     // symlink destination
        public bool $targetIsDir = false
    ) {
    }

    public function ext(): string
    {
        return $this->type === 'dir' ? '' : strtolower(pathinfo($this->name, PATHINFO_EXTENSION));
    }

    public function isDir(): bool
    {
        return $this->type === 'dir' || ($this->type === 'link' && $this->targetIsDir);
    }

    public function toArray(): array
    {
        $ext = $this->ext();

        return [
            'name'     => $this->name,
            'path'     => $this->path,
            'type'     => $this->type,
            'isDir'    => $this->isDir(),
            'size'     => $this->size,
            'mtime'    => $this->mtime,
            'perms'    => $this->perms,
            'permsOct' => $this->perms === null ? null : sprintf('%04o', $this->perms),
            'owner'    => $this->owner,
            'group'    => $this->group,
            'target'   => $this->target,
            'ext'      => $ext,
            'icon'     => $this->isDir() ? 'folder' : Mime::icon($ext),
            'kind'     => $this->isDir() ? 'folder' : Mime::colour($ext),
            'editable' => !$this->isDir() && Mime::editable($ext),
            'hidden'   => str_starts_with($this->name, '.'),
        ];
    }
}
