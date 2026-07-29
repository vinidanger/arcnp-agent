<?php

namespace App\Actions\Files;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\LinuxUsername;
use RuntimeException;

class RenameFileAction implements AgentAction
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
        $from = (string) ($payload['from'] ?? '');
        $to = (string) ($payload['to'] ?? '');
        $root = blank($payload['root'] ?? null) ? null : $payload['root'];

        if ($from === '' || $to === '') {
            throw new RuntimeException('from e to são obrigatórios.');
        }

        $this->processRunner->manageFile($username, 'rename', $from, $to, root: $root);

        return ['from' => $from, 'to' => $to];
    }
}
