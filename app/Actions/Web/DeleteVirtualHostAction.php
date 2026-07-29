<?php

namespace App\Actions\Web;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\DomainName;
use App\Support\NginxVhost;
use Illuminate\Support\Facades\File;

class DeleteVirtualHostAction implements AgentAction
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
        $configPath = NginxVhost::configPath($domain);

        if (File::exists($configPath)) {
            File::delete($configPath);
            $this->processRunner->reloadNginx();
        }

        return ['domain' => $domain, 'deleted' => true];
    }
}
