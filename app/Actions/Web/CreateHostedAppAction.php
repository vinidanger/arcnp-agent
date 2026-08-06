<?php

namespace App\Actions\Web;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Services\System\TemplateRenderer;
use App\Support\AppRuntime;
use App\Support\DocumentRoot;
use App\Support\DomainName;
use App\Support\FileManagerPath;
use App\Support\HostedAppUnit;
use App\Support\LinuxUsername;
use App\Support\NginxVhost;
use App\Support\Subdirectory;
use App\Support\WafDirectives;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Throwable;

/**
 * Troca o vhost de um domínio JÁ EXISTENTE (criado do jeito normal,
 * PHP, via CreateVirtualHostAction/CreateAddonDomainAction) pro modo
 * proxy — não cria domínio novo. Cria+habilita o unit systemd do app
 * PRIMEIRO; se a troca do vhost falhar depois, desfaz o unit antes de
 * propagar o erro (mesmo padrão de rollback do resto do painel).
 */
class CreateHostedAppAction implements AgentAction
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
        $runtime = (string) ($payload['runtime'] ?? '');
        $entryFile = (string) ($payload['entry_file'] ?? '');
        $port = (int) ($payload['port'] ?? 0);
        $sslActive = (bool) ($payload['ssl_active'] ?? false);

        if ($appId <= 0) {
            throw new InvalidArgumentException('ID de app inválido.');
        }

        // 20000-65535: mesma faixa documentada no deploy, nunca
        // exposta em firewall (só o nginx fala com ela via loopback) —
        // revalidada aqui em defesa de profundidade, nunca confiando
        // só na alocação do Painel.
        if ($port < 20000 || $port > 65535) {
            throw new InvalidArgumentException("Porta de app inválida: {$port}");
        }

        $binary = AppRuntime::binary($runtime);

        $homeDir = config('provisioning.home_base_dir')."/{$username}";
        $documentRoot = DocumentRoot::resolve($homeDir, $domain, $location, $subdir);

        // Confere que o arquivo de entrada já existe dentro do
        // document root — mesma defesa (../, symlink) que o
        // gerenciador de arquivos já usa pro mesmo conceito de raiz.
        $root = $location === 'outside' ? $domain : null;
        $relativeEntry = $subdir ? "{$subdir}/{$entryFile}" : $entryFile;
        $entryPath = FileManagerPath::resolveExisting($username, $relativeEntry, $root);

        $unit = HostedAppUnit::name($appId);
        $unitContent = $this->templateRenderer->render('app-service', [
            'domain' => $domain,
            'working_directory' => $documentRoot,
            'exec_start' => "{$binary} {$entryPath}",
            'port' => $port,
            'username' => $username,
            // Mesmo cgroup do PHP-FPM/cron/SSH da conta (user-{uid}.slice,
            // ver resources.set_limits) — sem isso o app Node/Python
            // ficaria de fora dos limites de CPU/RAM/processos do plano.
            'uid' => $this->processRunner->userId($username),
        ]);

        $this->processRunner->createAppUnit($unit, $unitContent);

        try {
            $wafDirectives = WafDirectives::render((bool) ($payload['waf_enabled'] ?? false));

            $vhostContents = $sslActive
                ? $this->templateRenderer->render('nginx-vhost-app-ssl', [
                    'domain' => $domain,
                    'document_root' => $documentRoot,
                    'app_port' => $port,
                    'ssl_cert_path' => "/etc/letsencrypt/live/{$domain}/fullchain.pem",
                    'ssl_cert_key_path' => "/etc/letsencrypt/live/{$domain}/privkey.pem",
                    'waf_directives' => $wafDirectives,
                ])
                : $this->templateRenderer->render('nginx-vhost-app', [
                    'domain' => $domain,
                    'document_root' => $documentRoot,
                    'app_port' => $port,
                    'waf_directives' => $wafDirectives,
                ]);

            File::put(NginxVhost::configPath($domain), $vhostContents);

            $this->processRunner->testNginxConfig();
            $this->processRunner->reloadNginx();
        } catch (Throwable $e) {
            $this->processRunner->removeAppUnit($unit);
            throw $e;
        }

        return ['domain' => $domain, 'unit' => $unit, 'port' => $port];
    }
}
