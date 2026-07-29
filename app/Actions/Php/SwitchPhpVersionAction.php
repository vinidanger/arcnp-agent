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

/**
 * Cria o pool na versão nova e só remove o da versão antiga depois
 * (não o contrário) — evita a conta ficar sem NENHUM pool ativo se
 * algo falhar no meio do caminho.
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

        $homeDir = config('provisioning.home_base_dir')."/{$username}";
        $newPoolPath = PhpFpmPool::poolConfigPath($username, $newVersion);

        if (File::exists($newPoolPath)) {
            throw new RuntimeException("Pool já existe na versão de destino: {$newVersion}");
        }

        $contents = $this->templateRenderer->render('php-fpm-pool', [
            'username' => $username,
            'socket_path' => PhpFpmPool::socketPath($username, $newVersion),
            'home_dir' => $homeDir,
        ]);

        File::put($newPoolPath, $contents);
        $this->processRunner->reloadPhpFpm($newVersion);

        $oldPoolPath = PhpFpmPool::poolConfigPath($username, $oldVersion);

        if (File::exists($oldPoolPath)) {
            File::delete($oldPoolPath);
            $this->processRunner->reloadPhpFpm($oldVersion);
        }

        return ['username' => $username, 'switched' => true];
    }
}
