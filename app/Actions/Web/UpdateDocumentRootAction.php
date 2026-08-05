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

/**
 * Reescreve um vhost já existente só pra mudar o public_path (a
 * subpasta que o nginx serve de fato — ex.: "public" pra um projeto
 * Laravel/Symfony extraído com a árvore inteira do framework). Mesmo
 * esqueleto do UpdateVirtualHostPhpVersionAction (mesma necessidade:
 * recalcular document_root, preservar SSL, testar+recarregar nginx),
 * só que o gatilho da mudança é outro.
 */
class UpdateDocumentRootAction implements AgentAction
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
        $subdir = blank($payload['subdir'] ?? null) ? null : Subdirectory::validate($payload['subdir']);
        $location = ($payload['location'] ?? null) === 'outside' ? 'outside' : null;
        $phpVersion = $payload['php_version'] ?? config('provisioning.default_php_version');
        PhpVersion::config($phpVersion);
        $sslActive = (bool) ($payload['ssl_active'] ?? false);
        $publicPath = blank($payload['public_path'] ?? null) ? null : Subdirectory::validate($payload['public_path']);

        $homeDir = config('provisioning.home_base_dir')."/{$username}";
        $documentRoot = DocumentRoot::resolve($homeDir, $domain, $location, $subdir, $publicPath);
        $socketPath = PhpFpmPool::socketPath($username, $phpVersion);

        if ($sslActive) {
            $contents = $this->templateRenderer->render('nginx-vhost-ssl', [
                'domain' => $domain,
                'document_root' => $documentRoot,
                'php_fpm_socket' => $socketPath,
                'ssl_cert_path' => "/etc/letsencrypt/live/{$domain}/fullchain.pem",
                'ssl_cert_key_path' => "/etc/letsencrypt/live/{$domain}/privkey.pem",
            ]);
        } else {
            $contents = $this->templateRenderer->render('nginx-vhost', [
                'domain' => $domain,
                'document_root' => $documentRoot,
                'php_fpm_socket' => $socketPath,
            ]);
        }

        File::put(NginxVhost::configPath($domain), $contents);

        $this->processRunner->testNginxConfig();
        $this->processRunner->reloadNginx();

        return ['domain' => $domain, 'document_root' => $documentRoot];
    }
}
