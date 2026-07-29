<?php

namespace App\Actions\Disk;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\LinuxUsername;

class DiskUsageAction implements AgentAction
{
    public function __construct(private ProcessRunner $processRunner)
    {
    }

    public function isAsync(): bool
    {
        return false;
    }

    public function execute(array $payload): array
    {
        $username = LinuxUsername::validate($payload['username'] ?? '');

        $bytes = $this->processRunner->diskUsageBytes($username);

        return ['username' => $username, 'used_mb' => (int) ceil($bytes / 1048576)];
    }
}
