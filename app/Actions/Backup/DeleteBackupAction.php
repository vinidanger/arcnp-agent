<?php

namespace App\Actions\Backup;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\LinuxUsername;
use RuntimeException;

class DeleteBackupAction implements AgentAction
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
        $files = $payload['files'] ?? [];

        if (! is_array($files) || $files === []) {
            throw new RuntimeException('files é obrigatório.');
        }

        $this->processRunner->deleteBackupFiles($username, $files);

        return ['username' => $username, 'deleted' => count($files)];
    }
}
