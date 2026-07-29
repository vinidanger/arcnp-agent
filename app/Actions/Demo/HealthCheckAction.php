<?php

namespace App\Actions\Demo;

use App\Actions\Contracts\AgentAction;

/**
 * Ação de prova, síncrona — valida o mecanismo de assinatura e despacho
 * sem tocar em nada do sistema. As ações reais de provisionamento entram
 * na Fase 5, quando existirem contas de hospedagem para provisionar.
 */
class HealthCheckAction implements AgentAction
{
    public function isAsync(): bool
    {
        return false;
    }

    public function execute(array $payload): array
    {
        return [
            'server_id' => config('agent.server_id'),
            'hostname' => gethostname(),
            'php_version' => PHP_VERSION,
            'checked_at' => now()->toIso8601String(),
        ];
    }
}
