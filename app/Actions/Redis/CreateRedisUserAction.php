<?php

namespace App\Actions\Redis;

use App\Actions\Contracts\AgentAction;
use App\Services\System\RedisAdmin;
use App\Support\LinuxUsername;
use RuntimeException;

/**
 * Usuário Redis isolado por ACL (~{username}:*) — mesmo espírito de
 * CreateMysqlMasterUserAction, idempotente (ACL SETUSER com "reset"
 * reaplica do zero, seguro de disparar de novo).
 */
class CreateRedisUserAction implements AgentAction
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
        $password = (string) ($payload['password'] ?? '');

        if (blank($password)) {
            throw new RuntimeException('password é obrigatório.');
        }

        $this->redis->createUser($username, $password);

        return ['username' => $username];
    }
}
