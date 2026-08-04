<?php

namespace App\Actions\Mail;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use InvalidArgumentException;

/**
 * Só leitura — tail de /var/log/maillog (Postfix, convenção RHEL/
 * AlmaLinux/Rocky; ver scripts/tail-log.sh). $search é opcional e faz
 * um grep de string fixa (não regex) nas últimas 5000 linhas — não é
 * busca full-text, só o suficiente pra rastrear um endereço específico
 * numa janela recente de log.
 */
class TailMailLogAction implements AgentAction
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
        $lines = min(max((int) ($payload['lines'] ?? 200), 1), self::MAX_LINES);
        $search = (string) ($payload['search'] ?? '');

        if (strlen($search) > 255) {
            throw new InvalidArgumentException('Termo de busca longo demais.');
        }

        $content = $this->processRunner->tailMailLog($lines, $search !== '' ? $search : null);

        return ['content' => $content];
    }
}
