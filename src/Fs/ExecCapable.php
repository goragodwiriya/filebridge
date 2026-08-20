<?php

declare(strict_types=1);

namespace FileBridge\Fs;

/** Drivers that can run a shell command on the remote host. */
interface ExecCapable
{
    public function exec(string $command): string;
}
