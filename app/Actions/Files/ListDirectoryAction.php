<?php

namespace App\Actions\Files;

use App\Actions\Contracts\AgentAction;
use App\Support\FileManagerPath;
use App\Support\LinuxUsername;
use InvalidArgumentException;

/**
 * Leitura direta (ACL de leitura do arcnpagent em public_html, ver
 * create-hosting-user.sh) — não precisa de sudo, ao contrário das
 * Actions que mutam arquivo.
 */
class ListDirectoryAction implements AgentAction
{
    public function isAsync(): bool
    {
        return false;
    }

    public function execute(array $payload): array
    {
        $username = LinuxUsername::validate($payload['username'] ?? '');
        $path = (string) ($payload['path'] ?? '');
        $root = blank($payload['root'] ?? null) ? null : $payload['root'];

        $dir = FileManagerPath::resolveExisting($username, $path, $root);

        if (! is_dir($dir)) {
            throw new InvalidArgumentException('Não é um diretório.');
        }

        $entries = [];

        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $full = $dir.'/'.$name;
            $isDir = is_dir($full);

            $entries[] = [
                'name' => $name,
                'type' => $isDir ? 'directory' : 'file',
                'size' => $isDir ? null : filesize($full),
                'modified_at' => date('c', filemtime($full)),
            ];
        }

        usort($entries, fn ($a, $b) => [$a['type'] === 'directory' ? 0 : 1, $a['name']] <=> [$b['type'] === 'directory' ? 0 : 1, $b['name']]);

        return ['path' => $path, 'entries' => $entries];
    }
}
