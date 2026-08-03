<?php

namespace App\Actions\Files;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\LinuxUsername;
use RuntimeException;

class ExtractArchiveAction implements AgentAction
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
        $path = (string) ($payload['path'] ?? '');
        $dest = (string) ($payload['dest'] ?? '');
        $root = blank($payload['root'] ?? null) ? null : $payload['root'];

        if ($path === '' || $dest === '') {
            throw new RuntimeException('path e dest são obrigatórios.');
        }

        $this->processRunner->extractArchive($username, $path, $dest, $root);

        return ['dest' => $dest];
    }
}
