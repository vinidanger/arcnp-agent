<?php

namespace App\Actions\Hosting;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\DomainName;
use App\Support\LinuxUsername;
use App\Support\NginxVhost;
use App\Support\PhpFpmPool;
use Illuminate\Support\Facades\File;

class ReactivateHostingAccountAction implements AgentAction
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
        $domain = DomainName::validate($payload['domain'] ?? '');

        $vhostPath = NginxVhost::configPath($domain);

        if (File::exists("{$vhostPath}.suspended")) {
            File::move("{$vhostPath}.suspended", $vhostPath);
        }

        $this->processRunner->testNginxConfig();
        $this->processRunner->reloadNginx();
        $this->processRunner->startPhpFpmService(PhpFpmPool::serviceName($username));

        return ['username' => $username, 'domain' => $domain, 'suspended' => false];
    }
}
