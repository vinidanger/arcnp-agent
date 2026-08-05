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
 * Muda quais zend_extension essa conta carrega (ex.: ioncube_loader) —
 * diferente de UpdatePhpFpmPoolSettingsAction (que só reescreve o .conf
 * e dá reload gracioso), aqui precisa reescrever TAMBÉM o .service
 * (Environment=PHP_INI_SCAN_DIR aponta pro php.ini próprio da conta,
 * ver PhpFpmPool::applyZendIni()) e reiniciar o processo —
 * zend_extension só é lido no boot, não tem como aplicar sem restart.
 * Corpo quase idêntico ao SwitchPhpVersionAction, mesmo motivo.
 */
class UpdateZendExtensionsAction implements AgentAction
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

        // $payload precisa trazer os settings atuais da conta inteiros
        // (não só zend_extensions) — mesmo cuidado de
        // HostingAccountProvisioningService::changePhpVersion(), senão
        // reescrever o .conf reseta memory_limit/extra_extensions/etc
        // sem querer.
        $variables = PhpFpmPoolSettings::variables($username, $phpVersion, $payload);

        File::put(PhpFpmPool::configPath($username), $this->templateRenderer->render('php-fpm-account', $variables));

        $variables['uid'] = $this->processRunner->userId($username);
        $zendDir = PhpFpmPool::applyZendIni($username, $variables['zend_ini_lines']);
        $variables['zend_ini_scan_dir_line'] = $zendDir === ''
            ? ''
            : 'Environment=PHP_INI_SCAN_DIR='.$zendDir.':'.PhpVersion::config($phpVersion)['ini_dir'];
        $serviceContent = $this->templateRenderer->render('php-fpm-account.service', $variables);

        $this->processRunner->applyPhpFpmService(PhpFpmPool::serviceName($username), $serviceContent);

        return ['username' => $username, 'zend_extensions' => $variables['zend_extensions']];
    }
}
