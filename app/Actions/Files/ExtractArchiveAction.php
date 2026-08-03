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

        // dest vazio é válido (extrair na raiz de public_html/domínio) —
        // mesma convenção de "string vazia = raiz" usada em todo o resto
        // do gerenciador de arquivos (ver FileManagerPath::join no
        // Painel e resolve() em manage-archive.sh). Só path precisa
        // mesmo ser não-vazio, já que não dá pra extrair "nada".
        if ($path === '') {
            throw new RuntimeException('path é obrigatório.');
        }

        $this->processRunner->extractArchive($username, $path, $dest, $root);

        return ['dest' => $dest];
    }
}
