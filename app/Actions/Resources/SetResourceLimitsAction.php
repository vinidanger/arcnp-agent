<?php

namespace App\Actions\Resources;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\LinuxUsername;

class SetResourceLimitsAction implements AgentAction
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

        // "infinity"/"100" (padrão neutro do systemd pro peso de I/O) são
        // os valores de "sem limite" — o Painel manda sempre os 4 campos
        // prontos (nunca parcial), e o script sudo revalida cada um antes
        // de tocar no cgroup (defesa em profundidade, igual todo o resto).
        $cpuPercent = (string) ($payload['cpu_percent'] ?? 'infinity');
        $memoryMb = (string) ($payload['memory_mb'] ?? 'infinity');
        $tasksMax = (string) ($payload['tasks_max'] ?? 'infinity');
        $ioWeight = (string) ($payload['io_weight'] ?? '100');

        $this->processRunner->setResourceLimits($username, $cpuPercent, $memoryMb, $tasksMax, $ioWeight);

        return ['username' => $username];
    }
}
