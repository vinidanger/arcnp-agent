<?php

namespace App\Actions\Hosting;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\DomainName;
use App\Support\LinuxUsername;
use App\Support\NginxVhost;
use App\Support\PhpFpmPool;
use Illuminate\Support\Facades\File;

/**
 * Desativa vhost (renomeia pra fora do padrão que o nginx carrega) e
 * para o processo PHP-FPM dedicado da conta — sem apagar nada, é
 * reversível pela ReactivateHostingAccountAction. Diferente de antes
 * (quando o pool era renomeado dentro de um service compartilhado),
 * agora a conta tem o próprio unit systemd, então "suspender o PHP" é
 * só parar o unit dela — nenhum outro processo é afetado.
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

        $vhostPath = NginxVhost::configPath($domain);

        if (File::exists($vhostPath)) {
            File::move($vhostPath, "{$vhostPath}.suspended");
        }

        $this->processRunner->testNginxConfig();
        $this->processRunner->reloadNginx();
        $this->processRunner->stopPhpFpmService(PhpFpmPool::serviceName($username));

        return ['username' => $username, 'domain' => $domain, 'suspended' => true];
    }
}
