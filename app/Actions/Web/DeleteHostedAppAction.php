<?php

namespace App\Actions\Web;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Services\System\TemplateRenderer;
use App\Support\DocumentRoot;
use App\Support\DomainName;
use App\Support\HostedAppUnit;
use App\Support\LinuxUsername;
use App\Support\NginxVhost;
use App\Support\PhpFpmPool;
use App\Support\PhpVersion;
use App\Support\Subdirectory;
use App\Support\WafDirectives;
use Illuminate\Support\Facades\File;

/**
 * Desfaz CreateHostedAppAction: remove o unit systemd e devolve o vhost
 * pro stub PHP normal — mesmo branch SSL que UpdateVirtualHostPhpVersionAction
 * já usa, já que o domínio volta a ser servido por PHP-FPM do jeito de sempre.
 */
class DeleteHostedAppAction implements AgentAction
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
        $appId = (int) ($payload['app_id'] ?? 0);
        $username = LinuxUsername::validate($payload['username'] ?? '');
        $domain = DomainName::validate($payload['domain'] ?? '');
        $subdir = blank($payload['subdir'] ?? null) ? null : Subdirectory::validate($payload['subdir']);
        $location = ($payload['location'] ?? null) === 'outside' ? 'outside' : null;
        $phpVersion = $payload['php_version'] ?? config('provisioning.default_php_version');
        PhpVersion::config($phpVersion);
        $sslActive = (bool) ($payload['ssl_active'] ?? false);

        $unit = HostedAppUnit::name($appId);
        $this->processRunner->removeAppUnit($unit);

        $homeDir = config('provisioning.home_base_dir')."/{$username}";
        $documentRoot = DocumentRoot::resolve($homeDir, $domain, $location, $subdir);
        $socketPath = PhpFpmPool::socketPath($username, $domain);

        $wafDirectives = WafDirectives::render((bool) ($payload['waf_enabled'] ?? false));

        if ($sslActive) {
            $contents = $this->templateRenderer->render('nginx-vhost-ssl', [
                'domain' => $domain,
                'document_root' => $documentRoot,
                'php_fpm_socket' => $socketPath,
                'ssl_cert_path' => "/etc/letsencrypt/live/{$domain}/fullchain.pem",
                'ssl_cert_key_path' => "/etc/letsencrypt/live/{$domain}/privkey.pem",
                'waf_directives' => $wafDirectives,
            ]);
        } else {
            $contents = $this->templateRenderer->render('nginx-vhost', [
                'domain' => $domain,
                'document_root' => $documentRoot,
                'php_fpm_socket' => $socketPath,
                'waf_directives' => $wafDirectives,
            ]);
        }

        File::put(NginxVhost::configPath($domain), $contents);

        $this->processRunner->testNginxConfig();
        $this->processRunner->reloadNginx();

        return ['domain' => $domain];
    }
}
