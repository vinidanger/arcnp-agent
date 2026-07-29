<?php

namespace App\Actions\Dns;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\DnsRecordValidator;
use App\Support\DnsZoneFile;
use App\Support\DnsZonesConf;
use App\Support\DomainName;
use RuntimeException;

/**
 * Ordem importa: escreve o zone file PRIMEIRO (sem recarregar),
 * depois reescreve zones.conf com a lista completa (incluindo esse
 * domínio) e recarrega o named inteiro — nessa ordem o arquivo já
 * existe quando o named tenta carregar a zona nova, então não falha
 * reclamando de arquivo ausente.
 */
class CreateDnsZoneAction implements AgentAction
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
        $nsHosts = $payload['ns'] ?? [];
        $adminEmail = (string) ($payload['admin_email'] ?? '');
        $records = array_map(fn (array $r) => DnsRecordValidator::validate($r), $payload['records'] ?? []);

        if (! in_array($domain, $zones, true)) {
            throw new RuntimeException('Domínio precisa estar incluído na lista de zonas.');
        }

        $zoneContent = DnsZoneFile::render($domain, $nsHosts, $adminEmail, $records);
        $this->processRunner->writeDnsZone($domain, $zoneContent);

        $zonesConfContent = DnsZonesConf::render($zones);
        $this->processRunner->syncDnsZonesConf($zonesConfContent);

        return ['domain' => $domain];
    }
}
