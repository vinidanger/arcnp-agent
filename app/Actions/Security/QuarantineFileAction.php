<?php

namespace App\Actions\Security;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\FtpChrootPath;
use App\Support\LinuxUsername;
use InvalidArgumentException;

class QuarantineFileAction implements AgentAction
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

        // Confirma que o arquivo existe de verdade dentro do home da
        // conta ANTES de despachar pro script sudo — mesma defesa em
        // profundidade usada pelo FTP (ver App\Support\FtpChrootPath).
        FtpChrootPath::resolveExisting($username, $path);

        if (! preg_match('/^[a-zA-Z0-9._-]+$/', $quarantineFilename)) {
            throw new InvalidArgumentException("Nome de quarentena inválido: {$quarantineFilename}");
        }

        $this->processRunner->quarantineFile($username, $path, $quarantineFilename);

        return ['quarantine_filename' => $quarantineFilename];
    }
}
