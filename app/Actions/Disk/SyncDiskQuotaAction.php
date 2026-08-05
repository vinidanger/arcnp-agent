<?php

namespace App\Actions\Disk;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\LinuxUsername;

class SyncDiskQuotaAction implements AgentAction
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
        $quotaMb = max(0, (int) ($payload['quota_mb'] ?? 0));

        $this->processRunner->setDiskQuota($username, $quotaMb);

        return ['username' => $username, 'quota_mb' => $quotaMb];
    }
}
