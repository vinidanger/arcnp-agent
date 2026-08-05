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
 * para TODOS os processos PHP-FPM da conta — sem apagar nada, é
 * reversível pela ReactivateHostingAccountAction. Desde que PHP passou
 * a ser por domínio (não mais 1 processo só por conta), uma conta pode
 * ter vários processos ao mesmo tempo (um por grupo versão+
 * zend_extensions em uso, ver PhpFpmPool) — precisa descobrir e parar
 * todos, não só um nome fixo.
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

        foreach (PhpFpmPool::allGroupKeysForUsername($username) as $groupKey) {
            $this->processRunner->stopPhpFpmService(PhpFpmPool::serviceName($username, $groupKey));
        }

        return ['username' => $username, 'domain' => $domain, 'suspended' => true];
    }
}
