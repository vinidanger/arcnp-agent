<?php

namespace App\Actions\Web;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Services\System\TemplateRenderer;
use App\Support\DocumentRoot;
use App\Support\DomainName;
use App\Support\LinuxUsername;
use App\Support\NginxVhost;
use App\Support\PhpFpmPool;
use App\Support\PhpVersion;
use App\Support\Subdirectory;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Domínio adicional/subdomínio — sempre usa o mesmo usuário Linux e o
 * mesmo pool PHP-FPM da conta principal. O document root muda conforme
 * location: "inside" fica num subdiretório dentro de public_html
 * (padrão histórico); "outside" ganha uma árvore própria em
 * domains/{domain}/public_html (mesma convenção do DirectAdmin).
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
        $location = ($payload['location'] ?? 'inside') === 'outside' ? 'outside' : 'inside';
        $phpVersion = $payload['php_version'] ?? config('provisioning.default_php_version');
        PhpVersion::config($phpVersion);

        $configPath = NginxVhost::configPath($domain);

        if (File::exists($configPath)) {
            throw new RuntimeException("Vhost já existe para: {$domain}");
        }

        $homeDir = config('provisioning.home_base_dir')."/{$username}";
        $subdir = $location === 'outside' ? null : Subdirectory::validate($payload['subdir'] ?? '');
        $target = $location === 'outside' ? $domain : $subdir;
        $publicPath = blank($payload['public_path'] ?? null) ? null : Subdirectory::validate($payload['public_path']);
        $documentRoot = DocumentRoot::resolve($homeDir, $domain, $location, $subdir, $publicPath);

        $this->processRunner->createAddonDirectory($username, $location, $target);

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
            $this->processRunner->deleteAddonDirectory($username, $location, $target);
            throw $e;
        }

        return ['domain' => $domain, 'document_root' => $documentRoot];
    }
}
