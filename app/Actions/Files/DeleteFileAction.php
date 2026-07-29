<?php

namespace App\Actions\Files;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\LinuxUsername;
use RuntimeException;

/**
 * Funciona pra arquivo ou diretório (delete recursivo no script) — a
 * raiz de public_html é bloqueada dentro do próprio manage-file.sh.
 */
class DeleteFileAction implements AgentAction
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

        if ($path === '') {
            throw new RuntimeException('path é obrigatório.');
        }

        $this->processRunner->manageFile($username, 'delete', $path);

        return ['path' => $path];
    }
}
