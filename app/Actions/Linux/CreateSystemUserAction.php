<?php

namespace App\Actions\Linux;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\LinuxUsername;
use RuntimeException;

class CreateSystemUserAction implements AgentAction
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

        if ($this->processRunner->userExists($username)) {
            throw new RuntimeException("Usuário já existe: {$username}");
        }

        $this->processRunner->createHostingUser($username);

        $homeDir = config('provisioning.home_base_dir')."/{$username}";

        return [
            'username' => $username,
            'home_dir' => $homeDir,
            'document_root' => "{$homeDir}/public_html",
        ];
    }
}
