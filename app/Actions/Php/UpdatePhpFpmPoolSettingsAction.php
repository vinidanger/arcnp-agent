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
 * Reescreve o pool JÁ EXISTENTE da conta (mesma versão de PHP, só os
 * valores tunáveis mudam) — diferente de CreatePhpFpmPoolAction/
 * SwitchPhpVersionAction, que lidam com pool novo/em versão diferente.
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

        $configPath = PhpFpmPool::poolConfigPath($username, $phpVersion);

        if (! File::exists($configPath)) {
            throw new RuntimeException("Pool PHP-FPM não encontrado para: {$username}");
        }

        $contents = $this->templateRenderer->render(
            'php-fpm-pool',
            PhpFpmPoolSettings::variables($username, $phpVersion, $payload),
        );

        File::put($configPath, $contents);
        $this->processRunner->reloadPhpFpm($phpVersion);

        return ['username' => $username];
    }
}
