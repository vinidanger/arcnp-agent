<?php

namespace App\Actions\Redis;

use App\Actions\Contracts\AgentAction;
use App\Services\System\RedisAdmin;
use App\Support\LinuxUsername;

/**
 * ACL DELUSER remove o usuário e todas as regras dele — não tem
 * "banco" pra apagar aqui, mesmo raciocínio de DeleteMysqlMasterUserAction.
 */
class DeleteRedisUserAction implements AgentAction
{
    public function __construct(private RedisAdmin $redis)
    {
    }

    public function isAsync(): bool
    {
        return false;
    }

    public function execute(array $payload): array
    {
        $username = LinuxUsername::validate($payload['username'] ?? '');

        $this->redis->deleteUser($username);

        return ['username' => $username, 'deleted' => true];
    }
}
