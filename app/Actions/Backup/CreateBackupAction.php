<?php

namespace App\Actions\Backup;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\LinuxUsername;
use App\Support\MysqlIdentifier;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Assíncrona — tar do public_html + dump de cada banco pode levar um
 * tempo em contas maiores. Dump roda sem privilégio (Agent já tem
 * acesso de leitura ao MySQL) num diretório temporário; o script sudo
 * (create-backup.sh) faz só o que exige privilégio: tarar o
 * public_html (dono é o usuário da conta) e mover os dumps já prontos
 * pra dentro do home dele.
 */
class CreateBackupAction implements AgentAction
{
    public function __construct(private ProcessRunner $processRunner)
    {
    }

    public function isAsync(): bool
    {
        return true;
    }

    public function execute(array $payload): array
    {
        $username = LinuxUsername::validate($payload['username'] ?? '');
        $databases = array_map(
            fn ($db) => MysqlIdentifier::validate($db),
            $payload['databases'] ?? []
        );
        $retention = max(1, (int) ($payload['retention'] ?? 5));

        $tmpDir = storage_path('app/backup-tmp/'.Str::uuid());
        File::makeDirectory($tmpDir, 0700, true);

        try {
            foreach ($databases as $db) {
                $this->processRunner->dumpMysqlDatabase($db, "{$tmpDir}/db-{$db}.sql.gz");
            }

            $files = $this->processRunner->createBackup($username, $retention, $tmpDir, $databases);
        } finally {
            File::deleteDirectory($tmpDir);
        }

        return ['username' => $username, 'files' => $files];
    }
}
