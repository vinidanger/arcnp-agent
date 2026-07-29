<?php

namespace App\Actions\Web;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Services\System\TemplateRenderer;
use App\Support\DomainName;
use App\Support\LinuxUsername;
use App\Support\NginxVhost;
use App\Support\PhpFpmPool;
use App\Support\PhpVersion;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Escreve o arquivo de config diretamente (sem sudo) — o diretório
 * nginx_conf_dir é group-writable para o usuário do Agent (ver
 * deploy/README.md). Só o "nginx -t" + reload exigem sudo, porque
 * são a parte que efetivamente aplica a mudança no serviço.
 */
class CreateVirtualHostAction implements AgentAction
{
    public function __construct(
        private ProcessRunner $processRunner,
        private TemplateRenderer $templateRenderer,
    ) {
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

        $homeDir = config('provisioning.home_base_dir')."/{$username}";
        $documentRoot = "{$homeDir}/public_html";
        $configPath = NginxVhost::configPath($domain);

        if (File::exists($configPath)) {
            throw new RuntimeException("Vhost já existe para: {$domain}");
        }

        $contents = $this->templateRenderer->render('nginx-vhost', [
            'domain' => $domain,
            'document_root' => $documentRoot,
            'php_fpm_socket' => PhpFpmPool::socketPath($username, $phpVersion),
        ]);

        File::put($configPath, $contents);

        try {
            $this->processRunner->testNginxConfig();
            $this->processRunner->reloadNginx();
        } catch (\Throwable $e) {
            File::delete($configPath);
            throw $e;
        }

        return ['domain' => $domain, 'config_path' => $configPath];
    }
}
