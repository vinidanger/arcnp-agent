<?php

namespace App\Actions\Web;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\HostedAppUnit;
use InvalidArgumentException;

class RestartHostedAppAction implements AgentAction
{
    public function __construct(
        private ProcessRunner $processRunner,
    ) {
    }

    public function isAsync(): bool
    {
        return false;
    }

    public function execute(array $payload): array
    {
        $appId = (int) ($payload['app_id'] ?? 0);

        if ($appId <= 0) {
            throw new InvalidArgumentException('ID de app inválido.');
        }

        $unit = HostedAppUnit::name($appId);
        $this->processRunner->restartAppUnit($unit);

        return ['restarted' => true];
    }
}
