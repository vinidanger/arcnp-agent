<?php

namespace App\Actions\Database;

use App\Actions\Contracts\AgentAction;
use App\Services\System\MysqlAdmin;
use App\Support\MysqlIdentifier;

/**
 * Aplica em QUALQUER usuário MySQL da conta (normal ou mestre) — quem
 * decide se é um limite de conta ou de banco específico é o payload que
 * o Painel manda, não esta Action. 0 = sem limite (mesma convenção do
 * resto do payload de recursos).
 */
class SetMysqlUserLimitsAction implements AgentAction
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
        $maxConnections = (int) ($payload['max_connections'] ?? 0);
        $maxQueriesPerHour = (int) ($payload['max_queries_per_hour'] ?? 0);

        $this->mysql->applyResourceLimits($dbUsername, $maxConnections, $maxQueriesPerHour);

        return ['db_username' => $dbUsername];
    }
}
