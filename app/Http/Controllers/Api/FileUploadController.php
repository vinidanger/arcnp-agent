<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\System\ProcessRunner;
use App\Support\LinuxUsername;
use Illuminate\Http\Request;
use Throwable;

/**
 * Upload binário — fora do sistema de Actions JSON de propósito
 * (arquivo pode ter bytes arbitrários, não cabe num campo string de
 * JSON sem base64, caro em CPU e em tamanho pra arquivo grande). Mesmo
 * esquema de assinatura das outras rotas (agent.signed), calculado
 * sobre o corpo bruto. Reaproveita a MESMA operação "write" do
 * manage-file.sh que a edição de arquivo texto já usa — upload é só
 * outra forma de "escrever esse conteúdo nesse caminho".
 */
class FileUploadController extends Controller
{
    public function store(Request $request, string $username, ProcessRunner $processRunner)
    {
        $username = LinuxUsername::validate($username);

        $path = rawurldecode((string) $request->header('X-Upload-Path'));
        $root = $request->header('X-Upload-Root');
        $root = blank($root) ? null : rawurldecode($root);

        abort_if($path === '', 400, 'Caminho de upload ausente.');

        try {
            $processRunner->manageFile($username, 'write', $path, content: $request->getContent(), root: $root);

            return response()->json(['status' => 'completed', 'path' => $path]);
        } catch (Throwable $e) {
            return response()->json(['status' => 'failed', 'error' => $e->getMessage()]);
        }
    }
}
