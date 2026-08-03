<?php

namespace App\Actions\Ssl;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;

/**
 * Disparada uma vez por dia por servidor (ver comando agendado
 * "ssl:renew" no Painel) — certbot decide sozinho o que precisa
 * renovar, não recebe lista de domínios.
 */
class RenewAllSslCertificatesAction implements AgentAction
{
    public function __construct(private ProcessRunner $processRunner)
    {
    }

    public function isAsync(): bool
    {
        return true;
    }

    public function execute(array $payload): array
    {
        $this->processRunner->renewAllSslCertificates();

        return ['renewed' => true];
    }
}
