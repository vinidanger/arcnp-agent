<?php

namespace App\Actions\Php;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Services\System\TemplateRenderer;
use App\Support\LinuxUsername;
use App\Support\PhpFpmPool;
use App\Support\PhpVersion;
use Illuminate\Support\Facades\File;
use RuntimeException;

class CreatePhpFpmPoolAction implements AgentAction
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
        $phpVersion = $payload['php_version'] ?? config('provisioning.default_php_version');
        PhpVersion::config($phpVersion); // valida a versão

        $homeDir = config('provisioning.home_base_dir')."/{$username}";
        $configPath = PhpFpmPool::poolConfigPath($username, $phpVersion);

        if (File::exists($configPath)) {
            throw new RuntimeException("Pool PHP-FPM já existe para: {$username}");
        }

        $contents = $this->templateRenderer->render('php-fpm-pool', [
            'username' => $username,
            'socket_path' => PhpFpmPool::socketPath($username, $phpVersion),
            'home_dir' => $homeDir,
        ]);

        File::put($configPath, $contents);

        try {
            $this->processRunner->reloadPhpFpm($phpVersion);
        } catch (\Throwable $e) {
            File::delete($configPath);
            throw $e;
        }

        return ['username' => $username, 'socket_path' => PhpFpmPool::socketPath($username, $phpVersion)];
    }
}
