<?php

namespace App\Actions\Linux;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\LinuxUsername;

class DeleteSystemUserAction implements AgentAction
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

        $this->processRunner->deleteHostingUser($username);

        return ['username' => $username, 'deleted' => true];
    }
}
