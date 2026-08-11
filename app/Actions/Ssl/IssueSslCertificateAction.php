<?php

namespace App\Actions\Ssl;

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
 * Assíncrona de propósito — depende de chamada de rede externa
 * (Let's Encrypt) que pode levar alguns segundos e falhar por motivos
 * fora do nosso controle (rate limit, validação de DNS, etc).
 *
 * Assume que o vhost HTTP simples (CreateVirtualHostAction) já existe
 * e está servindo o domínio na porta 80 — é contra ele que o certbot
 * valida via webroot (o próprio stub HTTP já deixa `.well-known`
 * passar, não precisa de nenhum preparo extra antes de emitir).
 */
class IssueSslCertificateAction implements AgentAction
{
    public function __construct(
        private ProcessRunner $processRunner,
        private TemplateRenderer $templateRenderer,
    ) {
    }

    public function isAsync(): bool
    {
        return true;
    }

    public function execute(array $payload): array
    {
        $username = LinuxUsername::validate($payload['username'] ?? '');
        $domain = DomainName::validate($payload['domain'] ?? '');
        $subdir = blank($payload['subdir'] ?? null) ? null : Subdirectory::validate($payload['subdir']);
        $location = ($payload['location'] ?? null) === 'outside' ? 'outside' : null;
        $mode = ($payload['mode'] ?? 'php') === 'app' ? 'app' : 'php';
        $publicPath = blank($payload['public_path'] ?? null) ? null : PublicPath::validate($payload['public_path']);

        $homeDir = config('provisioning.home_base_dir')."/{$username}";
        $documentRoot = $mode === 'app' ? DocumentRoot::resolve($homeDir, $domain, $location, $subdir) : DocumentRoot::resolve($homeDir, $domain, $location, $subdir, $publicPath);

        $expiresAt = $this->processRunner->issueSslCertificate($domain, $documentRoot);

        $certPath = "/etc/letsencrypt/live/{$domain}/fullchain.pem";
        $keyPath = "/etc/letsencrypt/live/{$domain}/privkey.pem";
        $wafDirectives = WafDirectives::render((bool) ($payload['waf_enabled'] ?? false));
        $cacheDirectives = CacheDirectives::render((bool) ($payload['cache_enabled'] ?? false), (int) ($payload['cache_version'] ?? 1));
        $http3Directives = Http3Directives::render((bool) ($payload['http3_enabled'] ?? false));

        if ($mode === 'app') {
            $appPort = (int) ($payload['app_port'] ?? 0);

            $contents = $this->templateRenderer->render('nginx-vhost-app-ssl', [
                'domain' => $domain,
                'document_root' => $documentRoot,
                'app_port' => $appPort,
                'ssl_cert_path' => $certPath,
                'ssl_cert_key_path' => $keyPath,
                'waf_directives' => $wafDirectives,
                'cache_directives' => $cacheDirectives,
                'http3_directives' => $http3Directives,
            ]);
        } else {
            $phpVersion = $payload['php_version'] ?? config('provisioning.default_php_version');
            PhpVersion::config($phpVersion);

            $contents = $this->templateRenderer->render('nginx-vhost-ssl', [
                'domain' => $domain,
                'document_root' => $documentRoot,
                'php_fpm_socket' => PhpFpmPool::socketPath($username, $domain),
                'ssl_cert_path' => $certPath,
                'ssl_cert_key_path' => $keyPath,
                'waf_directives' => $wafDirectives,
                'cache_directives' => $cacheDirectives,
                'http3_directives' => $http3Directives,
            ]);
        }

        NginxVhost::writeTested(NginxVhost::configPath($domain), $contents, $this->processRunner);

        return ['domain' => $domain, 'ssl_issued' => true, 'expires_at' => $expiresAt];
    }
}
