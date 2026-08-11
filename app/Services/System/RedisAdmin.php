<?php

namespace App\Services\System;

use App\Support\LinuxUsername;
use Illuminate\Support\Facades\Redis;

/**
 * Único ponto do Agent que fala com o Redis administrativo — mesmo
 * papel de MysqlAdmin, mas pro cache de objeto (paridade com
 * LSCache/object cache do LiteSpeed). Usa a conexão `redis.default`
 * (senha admin via REDIS_PASSWORD no .env do Agent, ver seção do
 * deploy/README.md) — sem shell pro `redis-cli`, via
 * executeRaw() (extensão phpredis já usada pelo client padrão do
 * Laravel, sem dependência de pacote novo via composer).
 *
 * Isolamento é por ACL (Redis 6+, uma instância compartilhada pro
 * servidor inteiro) — cada usuário só enxerga chaves com o próprio
 * prefixo ("~{username}:*"), nunca por "banco nomeado" (Redis não tem
 * esse conceito como o MySQL). "reset" no início limpa qualquer regra
 * anterior antes de aplicar a nova — idempotente, seguro reaplicar.
 */
class RedisAdmin
{
    public function createUser(string $username, string $password): void
    {
        LinuxUsername::validate($username);

        Redis::connection('default')->executeRaw([
            'ACL', 'SETUSER', $username,
            'reset', 'on', ">{$password}",
            "~{$username}:*", '+@all', 'resetchannels',
        ]);
    }

    public function deleteUser(string $username): void
    {
        LinuxUsername::validate($username);

        Redis::connection('default')->executeRaw(['ACL', 'DELUSER', $username]);
    }
}
