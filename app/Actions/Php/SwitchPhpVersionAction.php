<?php

namespace App\Actions\Php;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Services\System\TemplateRenderer;
use App\Support\LinuxUsername;
use App\Support\PhpFpmPool;
use App\Support\PhpFpmPoolSettings;
use App\Support\PhpVersion;
use Illuminate\Support\Facades\File;

/**
 * Identidade do unit é a CONTA, não a versão (arcnp-php-{username}.service
 * sempre) — diferente de antes (quando cada versão tinha um service
 * próprio compartilhado), trocar de versão aqui é só reescrever o
 * config + o unit (ExecStart aponta pro binário novo) e reaplicar via
 * "apply" (idempotente: escreve, habilita, reinicia).
 */
class SwitchPhpVersionAction implements AgentAction
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
        $oldVersion = $payload['old_php_version'] ?? '';
        $newVersion = $payload['new_php_version'] ?? '';
        PhpVersion::config($oldVersion);
        PhpVersion::config($newVersion);

        if ($oldVersion === $newVersion) {
            return ['username' => $username, 'switched' => false];
        }

        // $payload carrega os pool settings atuais da conta (se ela já
        // tiver algum customizado) — sem isso, toda troca de versão
        // silenciosamente resetaria memory_limit/upload_max_filesize/etc
        // de volta pro padrão global.
        $variables = PhpFpmPoolSettings::variables($username, $newVersion, $payload);

        File::put(PhpFpmPool::configPath($username), $this->templateRenderer->render('php-fpm-account', $variables));

        $variables['uid'] = $this->processRunner->userId($username);
        $serviceContent = $this->templateRenderer->render('php-fpm-account.service', $variables);

        $this->processRunner->applyPhpFpmService(PhpFpmPool::serviceName($username), $serviceContent);

        return ['username' => $username, 'switched' => true];
    }
}
