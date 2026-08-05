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

        $configPath = PhpFpmPool::configPath($username);

        if (File::exists($configPath)) {
            throw new RuntimeException("Config de PHP-FPM já existe para: {$username}");
        }

        $variables = PhpFpmPoolSettings::variables($username, $phpVersion, $payload);

        File::put($configPath, $this->templateRenderer->render('php-fpm-account', $variables));

        $variables['uid'] = $this->processRunner->userId($username);
        $zendDir = PhpFpmPool::applyZendIni($username, $variables['zend_ini_lines']);
        $variables['zend_ini_scan_dir_line'] = $zendDir === ''
            ? ''
            : 'Environment=PHP_INI_SCAN_DIR='.$zendDir.':'.PhpVersion::config($phpVersion)['ini_dir'];
        $serviceContent = $this->templateRenderer->render('php-fpm-account.service', $variables);

        try {
            $this->processRunner->applyPhpFpmService(PhpFpmPool::serviceName($username), $serviceContent);
        } catch (\Throwable $e) {
            File::delete($configPath);
            PhpFpmPool::applyZendIni($username, '');
            throw $e;
        }

        return ['username' => $username, 'socket_path' => PhpFpmPool::socketPath($username)];
    }
}
