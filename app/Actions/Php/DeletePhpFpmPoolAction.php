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
        $configPath = PhpFpmPool::poolConfigPath($username);

        if (File::exists($configPath)) {
            File::delete($configPath);
            $this->processRunner->reloadPhpFpm();
        }

        return ['username' => $username, 'deleted' => true];
    }
}
