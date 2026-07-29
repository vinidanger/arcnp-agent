<?php

namespace App\Actions\Files;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\LinuxUsername;
use RuntimeException;

class WriteFileAction implements AgentAction
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
        $content = $payload['content'] ?? null;
        $root = blank($payload['root'] ?? null) ? null : $payload['root'];

        if ($path === '' || $content === null) {
            throw new RuntimeException('path e content são obrigatórios.');
        }

        $this->processRunner->manageFile($username, 'write', $path, content: $content, root: $root);

        return ['path' => $path];
    }
}
