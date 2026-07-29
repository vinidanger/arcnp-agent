<?php

namespace App\Actions\Web;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\DomainName;
use App\Support\LinuxUsername;
use App\Support\NginxVhost;
use App\Support\Subdirectory;
use Illuminate\Support\Facades\File;

class DeleteAddonDomainAction implements AgentAction
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
        $subdir = Subdirectory::validate($payload['subdir'] ?? '');

        $configPath = NginxVhost::configPath($domain);

        if (File::exists($configPath)) {
            File::delete($configPath);
            $this->processRunner->reloadNginx();
        }

        $this->processRunner->deleteAddonDirectory($username, $subdir);

        return ['domain' => $domain, 'deleted' => true];
    }
}
