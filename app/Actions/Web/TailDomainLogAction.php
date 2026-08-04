<?php

namespace App\Actions\Web;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\DomainName;
use InvalidArgumentException;

/**
 * Só leitura — tail dos logs de access/error do nginx desse domínio
 * (caminho já fixo no stub do vhost, ver seção 22/CreateVirtualHostAction).
 * O caminho do arquivo é montado inteiramente dentro do script sudo
 * (scripts/tail-log.sh), nunca aceito pronto daqui.
 */
class TailDomainLogAction implements AgentAction
{
    private const MAX_LINES = 1000;

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
        $type = (string) ($payload['type'] ?? '');

        if (! in_array($type, ['access', 'error'], true)) {
            throw new InvalidArgumentException('Tipo de log inválido — use access ou error.');
        }

        $lines = min(max((int) ($payload['lines'] ?? 200), 1), self::MAX_LINES);

        $content = $this->processRunner->tailNginxLog($domain, $type, $lines);

        return ['domain' => $domain, 'type' => $type, 'content' => $content];
    }
}
