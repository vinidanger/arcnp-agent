<?php

namespace App\Actions\Database;

use App\Actions\Contracts\AgentAction;
use App\Services\System\MysqlAdmin;
use App\Support\MysqlIdentifier;
use RuntimeException;

class CreateMysqlDatabaseAction implements AgentAction
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
        $dbName = MysqlIdentifier::validate($payload['db_name'] ?? '');
        $dbUsername = MysqlIdentifier::validate($payload['db_username'] ?? '');
        $dbPassword = (string) ($payload['db_password'] ?? '');

        if (blank($dbPassword)) {
            throw new RuntimeException('db_password é obrigatório.');
        }

        $this->mysql->createDatabase($dbName);
        $this->mysql->createUser($dbUsername, $dbPassword);
        $this->mysql->grantAllPrivileges($dbName, $dbUsername);

        return ['db_name' => $dbName, 'db_username' => $dbUsername];
    }
}
