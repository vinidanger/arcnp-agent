<?php

namespace App\Actions\Php;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\LinuxUsername;
use App\Support\PhpFpmPool;
use Illuminate\Support\Facades\File;

class DeletePhpFpmPoolAction implements AgentAction
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

        $this->processRunner->removePhpFpmService(PhpFpmPool::serviceName($username));

        $configPath = PhpFpmPool::configPath($username);

        if (File::exists($configPath)) {
            File::delete($configPath);
        }

        PhpFpmPool::applyZendIni($username, '');

        return ['username' => $username, 'deleted' => true];
    }
}
