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
        // Arquivo vazio é um conteúdo válido pra salvar (ex.: criar o
        // arquivo e salvar sem digitar nada ainda) — só path é
        // realmente obrigatório aqui.
        $content = (string) ($payload['content'] ?? '');
        $root = blank($payload['root'] ?? null) ? null : $payload['root'];

        if ($path === '') {
            throw new RuntimeException('path é obrigatório.');
        }

        $this->processRunner->manageFile($username, 'write', $path, content: $content, root: $root);

        return ['path' => $path];
    }
}
