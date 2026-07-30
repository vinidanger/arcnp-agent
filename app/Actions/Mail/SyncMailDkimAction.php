<?php

namespace App\Actions\Mail;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\DomainName;
use App\Support\OpenDkimTables;

/**
 * Lista COMPLETA de domínios com DKIM ativo no servidor (mesmo padrão
 * do sync_state) — gera a chave que ainda não existir (idempotente) e
 * devolve o valor do TXT de TODOS os domínios da lista, não só o novo,
 * pra o Painel poder atualizar o que tiver guardado de qualquer um.
 */
class SyncMailDkimAction implements AgentAction
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
        $domains = array_map(fn ($d) => DomainName::validate($d), $payload['domains'] ?? []);

        $records = [];

        foreach ($domains as $domain) {
            $records[$domain] = $this->processRunner->generateDkimKey($domain);
        }

        $this->processRunner->syncDkimTables(OpenDkimTables::bundle($domains));

        return ['records' => $records];
    }
}
