<?php

namespace App\Actions\Security;

use App\Actions\Contracts\AgentAction;
use App\Support\FileManagerPath;
use App\Support\LinuxUsername;

/**
 * Só leitura de um arquivo já existente — reaproveita FileManagerPath
 * (mesma raiz que o instalador de WordPress usa: public_html, ou
 * domains/{root}/public_html quando instalado fora dela) pra resolver
 * o diretório de instalação com segurança, em vez de montar o caminho
 * na mão. Extrai a versão sem executar PHP nenhum — só regex em cima
 * do wp-includes/version.php.
 */
class CheckWordPressVersionAction implements AgentAction
{
    public function isAsync(): bool
    {
        return false;
    }

    public function execute(array $payload): array
    {
        $username = LinuxUsername::validate($payload['username'] ?? '');
        $destRelpath = (string) ($payload['dest_relpath'] ?? '');
        $root = (string) ($payload['root'] ?? '');

        try {
            $installDir = FileManagerPath::resolveExisting($username, $destRelpath, $root ?: null);
        } catch (\InvalidArgumentException) {
            return ['installed_version' => null];
        }

        $versionFile = $installDir.'/wp-includes/version.php';

        if (! is_file($versionFile)) {
            return ['installed_version' => null];
        }

        $contents = file_get_contents($versionFile);

        preg_match('/\$wp_version\s*=\s*\'([^\']+)\'/', $contents, $matches);

        return ['installed_version' => $matches[1] ?? null];
    }
}
