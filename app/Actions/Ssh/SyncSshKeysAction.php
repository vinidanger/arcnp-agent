<?php

namespace App\Actions\Ssh;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\LinuxUsername;
use App\Support\SshPublicKey;

class SyncSshKeysAction implements AgentAction
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
        $keys = array_map(
            fn (string $key) => SshPublicKey::validate($key),
            $payload['keys'] ?? []
        );

        $this->processRunner->syncSshKeys($username, implode("\n", $keys));

        return ['username' => $username, 'count' => count($keys)];
    }
}
