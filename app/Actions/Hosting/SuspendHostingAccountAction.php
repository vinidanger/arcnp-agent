<?php

namespace App\Actions\Hosting;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\DomainName;
use App\Support\LinuxUsername;
use App\Support\NginxVhost;
use App\Support\PhpFpmPool;
use App\Support\PhpVersion;
use Illuminate\Support\Facades\File;

/**
 * Desativa vhost e pool sem apagar nada — só renomeia pra fora do
 * padrão que nginx/php-fpm carregam (*.conf), depois recarrega. É
 * reversível pela ReactivateHostingAccountAction.
 */
class SuspendHostingAccountAction implements AgentAction
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
        $phpVersion = $payload['php_version'] ?? config('provisioning.default_php_version');
        PhpVersion::config($phpVersion);

        $vhostPath = NginxVhost::configPath($domain);
        $poolPath = PhpFpmPool::poolConfigPath($username, $phpVersion);

        if (File::exists($vhostPath)) {
            File::move($vhostPath, "{$vhostPath}.suspended");
        }

        if (File::exists($poolPath)) {
            File::move($poolPath, "{$poolPath}.suspended");
        }

        $this->processRunner->testNginxConfig();
        $this->processRunner->reloadNginx();
        $this->processRunner->reloadPhpFpm($phpVersion);

        return ['username' => $username, 'domain' => $domain, 'suspended' => true];
    }
}
