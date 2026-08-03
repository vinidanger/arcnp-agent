<?php

namespace App\Actions\Files;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\LinuxUsername;
use RuntimeException;

class CompressFilesAction implements AgentAction
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
        $paths = $payload['paths'] ?? [];
        $output = (string) ($payload['output'] ?? '');
        $root = blank($payload['root'] ?? null) ? null : $payload['root'];

        if ($paths === [] || $output === '') {
            throw new RuntimeException('paths e output são obrigatórios.');
        }

        $this->processRunner->compressFiles($username, $paths, $output, $root);

        return ['output' => $output];
    }
}
