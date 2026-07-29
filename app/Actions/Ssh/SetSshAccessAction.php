<?php

namespace App\Actions\Ssh;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\LinuxUsername;

class SetSshAccessAction implements AgentAction
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
        $enabled = (bool) ($payload['enabled'] ?? false);

        $this->processRunner->setSshAccess($username, $enabled);

        return ['username' => $username, 'enabled' => $enabled];
    }
}
