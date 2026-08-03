<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\LinuxUsername;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Junta vários arquivos de um mesmo backup num zip só, pro download
 * "Completo"/"Bancos de dados" do Painel. Não precisa de privilégio —
 * mesma leitura via ACL que o BackupDownloadController já usa (ver esse
 * controller), só que escrevendo o zip no tmp do próprio Agent em vez
 * de servir o arquivo original direto.
 */
class BackupBundleDownloadController extends Controller
{
    public function show(string $username, string $token)
    {
        $username = LinuxUsername::validate($username);

        $decoded = json_decode(rawurldecode($token), true);
        abort_if(! is_array($decoded) || empty($decoded['files']) || ! is_array($decoded['files']), 400, 'Token inválido.');

        $backupDir = config('provisioning.home_base_dir')."/{$username}/backups";
        $paths = [];

        foreach ($decoded['files'] as $filename) {
            if (! is_string($filename) || ! preg_match('/^[a-zA-Z0-9._-]+$/', $filename) || str_contains($filename, '..')) {
                abort(400, 'Nome de arquivo inválido.');
            }

            $path = "{$backupDir}/{$filename}";
            abort_unless(is_file($path), 404);
            $paths[] = $path;
        }

        $tmpDir = storage_path('app/backup-zip-tmp');
        File::ensureDirectoryExists($tmpDir, 0700);
        $tmpZip = "{$tmpDir}/".Str::uuid().'.zip';

        $result = Process::timeout(120)->run(['zip', '-j', '-q', $tmpZip, ...$paths]);

        if ($result->failed()) {
            File::delete($tmpZip);
            abort(500, 'Falha ao compactar: '.trim($result->errorOutput() ?: $result->output()));
        }

        return response()->download($tmpZip, 'backup.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }
}
