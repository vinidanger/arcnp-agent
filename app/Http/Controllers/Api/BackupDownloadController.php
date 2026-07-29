<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\LinuxUsername;

/**
 * Serve o arquivo de backup pro Painel fazer o proxy pro navegador do
 * admin/cliente. Protegida pelo mesmo middleware agent.signed das
 * demais rotas — não é uma Action (o contrato AgentAction devolve JSON,
 * não é feito pra streaming de arquivo binário).
 *
 * Leitura só funciona porque create-backup.sh concede ACL de leitura
 * pro usuário arcnpagent nesses arquivos especificamente (ver
 * scripts/create-backup.sh) — o Agent não tem acesso a mais nada
 * dentro do home do cliente.
 */
class BackupDownloadController extends Controller
{
    public function show(string $username, string $filename)
    {
        $username = LinuxUsername::validate($username);

        if (! preg_match('/^[a-zA-Z0-9._-]+$/', $filename) || str_contains($filename, '..')) {
            abort(400, 'Nome de arquivo inválido.');
        }

        $path = config('provisioning.home_base_dir')."/{$username}/backups/{$filename}";

        abort_unless(is_file($path), 404);

        return response()->download($path, $filename, ['Content-Type' => 'application/octet-stream']);
    }
}
