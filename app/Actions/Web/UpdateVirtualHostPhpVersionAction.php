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
use App\Support\PublicPath;
use App\Support\CacheDirectives;
use App\Support\Http3Directives;
use App\Support\Subdirectory;
use App\Support\WafDirectives;

/**
 * Reescreve um vhost já existente pra apontar pro socket certo do
 * domínio — preserva o bloco SSL se ele já tiver certificado ativo.
 * Desde que PHP passou a ser por domínio, o socket (PhpFpmPool::
 * socketPath()) só depende do domínio, nunca da versão de PHP — trocar
 * a versão de um domínio (SyncAccountPhpPoolsAction) NUNCA precisa
 * mais reescrever o vhost dele. Essa Action continua existindo pra
 * outros gatilhos que ainda re-renderizam o vhost inteiro (o comando
 * de migração `php-fpm:migrate-to-per-domain-pools`, que precisa
 * repontar vhosts antigos pro esquema de socket novo).
 */
class UpdateVirtualHostPhpVersionAction implements AgentAction
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
        $publicPath = blank($payload['public_path'] ?? null) ? null : PublicPath::validate($payload['public_path']);

        $homeDir = config('provisioning.home_base_dir')."/{$username}";
        $documentRoot = DocumentRoot::resolve($homeDir, $domain, $location, $subdir, $publicPath);
        $socketPath = PhpFpmPool::socketPath($username, $domain);

        $wafDirectives = WafDirectives::render((bool) ($payload['waf_enabled'] ?? false));
        $cacheDirectives = CacheDirectives::render((bool) ($payload['cache_enabled'] ?? false), (int) ($payload['cache_version'] ?? 1));

        if ($sslActive) {
            $contents = $this->templateRenderer->render('nginx-vhost-ssl', [
                'domain' => $domain,
                'document_root' => $documentRoot,
                'php_fpm_socket' => $socketPath,
                'ssl_cert_path' => "/etc/letsencrypt/live/{$domain}/fullchain.pem",
                'ssl_cert_key_path' => "/etc/letsencrypt/live/{$domain}/privkey.pem",
                'waf_directives' => $wafDirectives,
                'cache_directives' => $cacheDirectives,
                'http3_directives' => Http3Directives::render((bool) ($payload['http3_enabled'] ?? false)),
            ]);
        } else {
            $contents = $this->templateRenderer->render('nginx-vhost', [
                'domain' => $domain,
                'document_root' => $documentRoot,
                'php_fpm_socket' => $socketPath,
                'waf_directives' => $wafDirectives,
                'cache_directives' => $cacheDirectives,
            ]);
        }

        NginxVhost::writeTested(NginxVhost::configPath($domain), $contents, $this->processRunner);

        return ['domain' => $domain, 'php_version' => $phpVersion];
    }
}
