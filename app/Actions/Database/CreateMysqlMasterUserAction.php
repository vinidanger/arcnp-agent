<?php

namespace App\Actions\Database;

use App\Actions\Contracts\AgentAction;
use App\Services\System\MysqlAdmin;
use App\Support\MysqlIdentifier;
use RuntimeException;

/**
 * Usuário MySQL "mestre" da conta — sem banco próprio, só um GRANT em
 * curinga sobre "{prefix}_%" (todos os bancos da conta). Idempotente
 * (createUser já é IF NOT EXISTS; reconceder o GRANT não tem efeito
 * colateral) — seguro de disparar de novo se o Painel já tiver criado
 * esse usuário antes.
 */
class CreateMysqlMasterUserAction implements AgentAction
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
        $dbPrefix = MysqlIdentifier::validate($payload['db_prefix'] ?? '');
        $dbUsername = MysqlIdentifier::validate($payload['db_username'] ?? '');
        $dbPassword = (string) ($payload['db_password'] ?? '');

        if (blank($dbPassword)) {
            throw new RuntimeException('db_password é obrigatório.');
        }

        $this->mysql->createUser($dbUsername, $dbPassword);
        $this->mysql->grantAllPrivilegesWildcard($dbPrefix, $dbUsername);

        return ['db_username' => $dbUsername];
    }
}
