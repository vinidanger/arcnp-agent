<?php

namespace App\Actions\Files;

use App\Actions\Contracts\AgentAction;
use App\Support\FileManagerPath;
use App\Support\LinuxUsername;
use InvalidArgumentException;
use RuntimeException;

/**
 * Só pra edição de texto/código — recusa arquivo grande demais ou que
 * não pareça texto (evita mandar binário/imagem pro editor do
 * navegador). Leitura direta, mesma ACL do ListDirectoryAction.
 */
class ReadFileAction implements AgentAction
{
    private const MAX_SIZE_BYTES = 2 * 1024 * 1024;

    public function isAsync(): bool
    {
        return false;
    }

    public function execute(array $payload): array
    {
        $username = LinuxUsername::validate($payload['username'] ?? '');
        $path = (string) ($payload['path'] ?? '');

        $file = FileManagerPath::resolveExisting($username, $path);

        if (! is_file($file)) {
            throw new InvalidArgumentException('Não é um arquivo.');
        }

        if (filesize($file) > self::MAX_SIZE_BYTES) {
            throw new RuntimeException('Arquivo grande demais pra editar aqui (limite 2 MB).');
        }

        $content = file_get_contents($file);

        if ($content === false) {
            throw new RuntimeException('Falha ao ler o arquivo.');
        }

        if (str_contains($content, "\0") || ! mb_check_encoding($content, 'UTF-8')) {
            throw new RuntimeException('Arquivo binário — não pode ser editado aqui.');
        }

        return ['path' => $path, 'content' => $content];
    }
}
