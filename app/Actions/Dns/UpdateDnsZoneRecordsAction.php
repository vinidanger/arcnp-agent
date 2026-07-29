<?php

namespace App\Actions\Dns;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\DnsRecordValidator;
use App\Support\DnsZoneFile;
use App\Support\DomainName;

/**
 * Zona já existe e já está em zones.conf — só reescreve o conteúdo do
 * zone file (o Painel sempre reenvia a lista COMPLETA de registros,
 * nunca edição incremental) e recarrega só essa zona.
 */
class UpdateDnsZoneRecordsAction implements AgentAction
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
        $nsHosts = $payload['ns'] ?? [];
        $adminEmail = (string) ($payload['admin_email'] ?? '');
        $records = array_map(fn (array $r) => DnsRecordValidator::validate($r), $payload['records'] ?? []);

        $zoneContent = DnsZoneFile::render($domain, $nsHosts, $adminEmail, $records);
        $this->processRunner->writeDnsZone($domain, $zoneContent);
        $this->processRunner->reloadDnsZone($domain);

        return ['domain' => $domain];
    }
}
