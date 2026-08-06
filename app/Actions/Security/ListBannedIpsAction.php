<?php

namespace App\Actions\Security;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;

class ListBannedIpsAction implements AgentAction
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
        return ['banned' => $this->processRunner->listBannedIps()];
    }
}
