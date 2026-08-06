<?php

namespace App\Actions\Security;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\LinuxUsername;
use InvalidArgumentException;

/**
 * O destino (path original) ainda não existe nesse ponto (é pra onde o
 * arquivo está voltando) — diferente de QuarantineFileAction, não dá
 * pra validar via FtpChrootPath::resolveExisting() (exige que já
 * exista). A checagem final de verdade é a mesma de sempre: o
 * script sudo (manage-quarantine.sh) revalida com realpath -m +
 * checagem de prefixo antes de mover qualquer coisa.
 */
class RestoreQuarantinedFileAction implements AgentAction
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
        $quarantineFilename = (string) ($payload['quarantine_filename'] ?? '');

        if ($path === '' || str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path)) {
            throw new InvalidArgumentException("Caminho inválido: {$path}");
        }

        if (! preg_match('/^[a-zA-Z0-9._-]+$/', $quarantineFilename)) {
            throw new InvalidArgumentException("Nome de quarentena inválido: {$quarantineFilename}");
        }

        $this->processRunner->restoreQuarantinedFile($username, $path, $quarantineFilename);

        return ['restored' => true];
    }
}
