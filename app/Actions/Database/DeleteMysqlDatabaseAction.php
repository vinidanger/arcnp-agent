<?php

namespace App\Actions\Database;

use App\Actions\Contracts\AgentAction;
use App\Services\System\MysqlAdmin;
use App\Support\MysqlIdentifier;

class DeleteMysqlDatabaseAction implements AgentAction
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

        $this->mysql->dropDatabase($dbName);
        $this->mysql->dropUser($dbUsername);

        return ['db_name' => $dbName, 'deleted' => true];
    }
}
