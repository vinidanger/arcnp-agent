<?php

namespace App\Actions\Ssh;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\LinuxUsername;
use RuntimeException;

class SetSshPasswordAction implements AgentAction
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
        $password = (string) ($payload['password'] ?? '');

        if ($password === '') {
            throw new RuntimeException('password é obrigatório.');
        }

        $this->processRunner->setSshPassword($username, $password);

        return ['username' => $username];
    }
}
