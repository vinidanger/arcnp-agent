<?php

namespace App\Actions\Web;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\LinuxUsername;

/**
 * Assíncrona — home grande pode levar bastante tempo pra converter
 * (ver timeout de 30min em ProcessRunner::optimizeImages()), mesmo
 * padrão de security.scan_account.
 */
class OptimizeImagesAction implements AgentAction
{
    public function __construct(private ProcessRunner $processRunner)
    {
    }

    public function isAsync(): bool
    {
        return true;
    }

    public function execute(array $payload): array
    {
        $username = LinuxUsername::validate($payload['username'] ?? '');

        return $this->processRunner->optimizeImages($username);
    }
}
