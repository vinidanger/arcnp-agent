<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\FileManagerPath;
use App\Support\LinuxUsername;

/**
 * Serve um arquivo dentro da raiz do gerenciador de arquivos pro
 * Painel fazer o proxy pro navegador (download ou <img src> de
 * preview) — mesma ACL de leitura do ListDirectoryAction/ReadFileAction
 * (sem sudo, o Agent já tem leitura via setfacl). Não é uma Action
 * (contrato devolve JSON, não streaming binário) — mesmo motivo do
 * BackupDownloadController.
 *
 * path/root vêm num único segmento de rota (JSON + rawurlencode) em
 * vez de querystring — assim o "path" assinado (RequestSigner, ver
 * VerifySignedRequest) bate exatamente com $request->path(), que não
 * inclui querystring.
 */
class FileDownloadController extends Controller
{
    public function show(string $username, string $token)
    {
        $username = LinuxUsername::validate($username);

        $decoded = json_decode(rawurldecode($token), true);
        abort_if(! is_array($decoded), 400, 'Token inválido.');

        $path = (string) ($decoded['path'] ?? '');
        $root = blank($decoded['root'] ?? null) ? null : (string) $decoded['root'];

        $file = FileManagerPath::resolveExisting($username, $path, $root);

        abort_unless(is_file($file), 404);

        return response()->download($file, basename($file));
    }
}
