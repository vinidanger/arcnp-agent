<?php

namespace App\Actions\Database;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\MysqlIdentifier;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Clone de staging — dump do banco de ORIGEM (produção) num arquivo
 * temporário, seguido de import no banco de DESTINO (já criado vazio
 * antes pelo Painel via database.create_mysql). Assíncrona: dump+import
 * de um banco grande pode levar um tempo, mesmo espírito de
 * CreateBackupAction.
 */
class CloneDatabaseAction implements AgentAction
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
        $sourceDb = MysqlIdentifier::validate($payload['source_database'] ?? '');
        $destDb = MysqlIdentifier::validate($payload['dest_database'] ?? '');

        $tmpDir = storage_path('app/db-clone-tmp/'.Str::uuid());
        File::makeDirectory($tmpDir, 0700, true);
        $dumpPath = "{$tmpDir}/dump.sql.gz";

        try {
            $this->processRunner->dumpMysqlDatabase($sourceDb, $dumpPath);
            $sqlContent = gzdecode(file_get_contents($dumpPath));
            $this->processRunner->importMysqlDatabase($destDb, $sqlContent);
        } finally {
            File::deleteDirectory($tmpDir);
        }

        return ['source_database' => $sourceDb, 'dest_database' => $destDb];
    }
}
