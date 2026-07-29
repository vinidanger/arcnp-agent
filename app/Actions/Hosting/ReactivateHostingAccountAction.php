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
        $poolPath = PhpFpmPool::poolConfigPath($username);

        if (File::exists("{$vhostPath}.suspended")) {
            File::move("{$vhostPath}.suspended", $vhostPath);
        }

        if (File::exists("{$poolPath}.suspended")) {
            File::move("{$poolPath}.suspended", $poolPath);
        }

        $this->processRunner->testNginxConfig();
        $this->processRunner->reloadNginx();
        $this->processRunner->reloadPhpFpm();

        return ['username' => $username, 'domain' => $domain, 'suspended' => false];
    }
}
