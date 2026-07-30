<?php

namespace App\Actions\Mail;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\DomainName;
use App\Support\OpenDkimTables;

/**
 * Remove a chave de UM domínio e resincroniza as tabelas com a lista
 * remanescente — ordem igual à do dns.delete_zone: primeiro tira o
 * domínio das tabelas (KeyTable/SigningTable) e recarrega, só depois
 * apaga o arquivo de chave.
 */
class DeleteMailDkimAction implements AgentAction
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
        $remainingDomains = array_map(fn ($d) => DomainName::validate($d), $payload['remaining_domains'] ?? []);

        $this->processRunner->syncDkimTables(OpenDkimTables::bundle($remainingDomains));
        $this->processRunner->deleteDkimKey($domain);

        return ['domain' => $domain];
    }
}
