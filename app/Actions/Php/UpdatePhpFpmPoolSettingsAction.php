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
use RuntimeException;

/**
 * Reescreve o config JÁ EXISTENTE da conta (mesma versão de PHP, só os
 * valores tunáveis mudam) — diferente de CreatePhpFpmPoolAction/
 * SwitchPhpVersionAction, que lidam com config novo/em versão
 * diferente. "reload" é gracioso (SIGUSR2, só recarrega o que o pool
 * lê), não precisa reescrever o unit nem reiniciar o processo mestre.
 */
class UpdatePhpFpmPoolSettingsAction implements AgentAction
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
        $phpVersion = $payload['php_version'] ?? '';
        PhpVersion::config($phpVersion);

        $configPath = PhpFpmPool::configPath($username);

        if (! File::exists($configPath)) {
            throw new RuntimeException("Config de PHP-FPM não encontrado para: {$username}");
        }

        $contents = $this->templateRenderer->render(
            'php-fpm-account',
            PhpFpmPoolSettings::variables($username, $phpVersion, $payload),
        );

        File::put($configPath, $contents);
        $this->processRunner->reloadPhpFpmService(PhpFpmPool::serviceName($username));

        return ['username' => $username];
    }
}
