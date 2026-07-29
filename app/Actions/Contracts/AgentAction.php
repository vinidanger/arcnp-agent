<?php

namespace App\Actions\Contracts;

interface AgentAction
{
    /**
     * Ações síncronas são executadas inline e respondidas na mesma requisição.
     * Ações assíncronas são enfileiradas e reportadas depois via callback ao Painel.
     */
    public function isAsync(): bool;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(array $payload): array;
}
