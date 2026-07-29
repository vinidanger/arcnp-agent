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
use App\Support\Subdirectory;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Domínio adicional/subdomínio — usa o mesmo usuário Linux e o mesmo
 * pool PHP-FPM da conta principal, só ganha um subdiretório dedicado
 * dentro de public_html e seu próprio vhost.
 */
class CreateAddonDomainAction implements AgentAction
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
        $subdir = Subdirectory::validate($payload['subdir'] ?? '');
        $phpVersion = $payload['php_version'] ?? config('provisioning.default_php_version');
        PhpVersion::config($phpVersion);

        $configPath = NginxVhost::configPath($domain);

        if (File::exists($configPath)) {
            throw new RuntimeException("Vhost já existe para: {$domain}");
        }

        $this->processRunner->createAddonDirectory($username, $subdir);

        $documentRoot = config('provisioning.home_base_dir')."/{$username}/public_html/{$subdir}";

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
            $this->processRunner->deleteAddonDirectory($username, $subdir);
            throw $e;
        }

        return ['domain' => $domain, 'document_root' => $documentRoot];
    }
}
