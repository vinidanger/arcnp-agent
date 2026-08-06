<?php

namespace App\Actions\Database;

use App\Actions\Contracts\AgentAction;
use App\Services\System\MysqlAdmin;
use App\Support\MysqlIdentifier;

/**
 * DROP USER já remove todos os GRANTs desse usuário sozinho — não tem
 * banco pra apagar aqui (o usuário mestre nunca foi dono de nenhum,
 * só tinha GRANT em curinga sobre os bancos de outros usuários).
 */
class DeleteMysqlMasterUserAction implements AgentAction
{
    public function __construct(private MysqlAdmin $mysql)
    {
    }

    public function isAsync(): bool
    {
        return false;
    }

    public function execute(array $payload): array
    {
        $dbUsername = MysqlIdentifier::validate($payload['db_username'] ?? '');

        $this->mysql->dropUser($dbUsername);

        return ['db_username' => $dbUsername, 'deleted' => true];
    }
}
