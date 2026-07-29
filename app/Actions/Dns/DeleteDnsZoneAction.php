<?php

namespace App\Actions\Dns;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\DnsZonesConf;
use App\Support\DomainName;

/**
 * Ordem inversa da criação: primeiro tira o domínio de zones.conf e
 * recarrega (o named solta a zona de forma limpa), só depois apaga o
 * arquivo — apagar o arquivo antes faria o named reclamar de zona sem
 * arquivo no reload.
 */
class DeleteDnsZoneAction implements AgentAction
{
    public function __construct(private ProcessRunner $processRunner)
    {
    }

    public function isAsync(): bool
    {
        return false;
    }

    public function execute(array $payload): array
    {
        $domain = DomainName::validate($payload['domain'] ?? '');
        $zones = array_map(fn ($d) => DomainName::validate($d), $payload['zones'] ?? []);

        $zonesConfContent = DnsZonesConf::render($zones);
        $this->processRunner->syncDnsZonesConf($zonesConfContent);

        $this->processRunner->deleteDnsZone($domain);

        return ['domain' => $domain];
    }
}
