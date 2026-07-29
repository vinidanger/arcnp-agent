<?php

namespace App\Actions\Cron;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\CronSchedule;
use App\Support\LinuxUsername;

/**
 * Reescreve /etc/cron.d/arcnp-{username} por inteiro a partir da lista
 * completa enviada — o Painel é sempre a fonte da verdade, nunca faz
 * diff incremental (mesmo padrão idempotente de outras Actions que
 * regravam arquivo de configuração inteiro).
 */
class SyncCronJobsAction implements AgentAction
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
        $username = LinuxUsername::validate($payload['username'] ?? '');
        $jobs = $payload['jobs'] ?? [];

        $lines = array_map(
            fn (array $job) => CronSchedule::formatLine($username, $job),
            $jobs
        );

        $content = implode("\n", $lines);

        $this->processRunner->syncCronJobs($username, $content);

        return ['username' => $username, 'count' => count($lines)];
    }
}
