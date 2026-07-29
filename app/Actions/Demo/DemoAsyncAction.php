<?php

namespace App\Actions\Demo;

use App\Actions\Contracts\AgentAction;

/**
 * Ação de prova, assíncrona — só para validar fila local + callback
 * assinado de volta ao Painel. Sem efeito real no sistema.
 */
class DemoAsyncAction implements AgentAction
{
    public function isAsync(): bool
    {
        return true;
    }

    public function execute(array $payload): array
    {
        sleep(2);

        return [
            'echo' => $payload,
            'finished_at' => now()->toIso8601String(),
        ];
    }
}
