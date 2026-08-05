<?php

namespace App\Support;

/**
 * Monta as variáveis do stub php-fpm-account.stub (config global+pool
 * combinada) — reaproveitado por CreatePhpFpmPoolAction,
 * SwitchPhpVersionAction e UpdatePhpFpmPoolSettingsAction pra não
 * triplicar o merge de defaults/overrides nos três. $overrides
 * ausente/vazio = usa config('provisioning.default_pool_settings')
 * integralmente. "binary"/"config_path" aqui servem pro
 * php-fpm-account.service.stub (unit systemd), não pro próprio
 * arquivo de config do FPM.
 */
class PhpFpmPoolSettings
{
    /**
     * Precisa bater exatamente com os placeholders {{...}} do stub e com
     * as chaves de config('provisioning.default_pool_settings') — o
     * Painel monta $overrides com esses mesmos nomes (ver
     * HostingAccountProvisioningService::formatPoolSettings).
     */
    public const TUNABLE_KEYS = [
        'memory_limit',
        'upload_max_filesize',
        'post_max_size',
        'max_execution_time',
        'max_input_time',
        'max_input_vars',
        'max_file_uploads',
        'session.gc_maxlifetime',
        'display_errors',
        'log_errors',
        'error_reporting',
        'file_uploads',
        'short_open_tag',
        'disable_functions',
        'extra_extensions',
        'zend_extensions',
    ];

    /**
     * Lista fechada de funções que a conta pode desabilitar no próprio
     * pool — mesma lista que o Painel mostra como checkbox. Diferente
     * dos outros TUNABLE_KEYS (que confiam no Painel sem revalidar,
     * lacuna pré-existente fora do escopo desta mudança), esse aqui
     * entra direto num diretiva de ini livre — vale a pena travar num
     * whitelist fechado antes de interpolar no arquivo.
     */
    public const DISABLABLE_FUNCTIONS = [
        'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'show_source',
    ];

    public static function sanitizeDisableFunctions(string $value): string
    {
        $requested = array_filter(array_map('trim', explode(',', $value)));
        $allowed = array_values(array_intersect($requested, self::DISABLABLE_FUNCTIONS));

        return implode(',', $allowed);
    }

    /**
     * "extra_extensions" é por conta, diferente do resto do gerenciamento
     * de extensões (que é por servidor/versão, ver PhpExtensionController
     * no Painel) — não dá pra usar um whitelist estático como
     * DISABLABLE_FUNCTIONS porque "quais extensões existem" varia por
     * servidor. Em vez disso revalida contra
     * PhpExtensionList::availableForPerAccountOptIn(), que já garante que
     * só extensões "extension" (não zend) e atualmente desativadas a
     * nível de servidor entram na lista — exatamente o conjunto seguro
     * pra ativar só num pool.
     */
    public static function sanitizeExtraExtensions(string $phpVersion, string $value): string
    {
        $requested = array_filter(array_map('trim', explode(',', $value)));
        $available = array_column(PhpExtensionList::availableForPerAccountOptIn($phpVersion), 'name');
        $allowed = array_values(array_intersect($requested, $available));

        return implode(',', $allowed);
    }

    /**
     * Gera as linhas php_admin_value[extension] pro placeholder
     * {{extra_extensions_lines}} do stub — um valor por linha, diferente
     * dos outros TUNABLE_KEYS (um placeholder = um valor), porque aqui
     * "n" extensões viram "n" diretivas de ini.
     */
    private static function renderExtraExtensionsLines(string $value): string
    {
        $names = array_filter(array_map('trim', explode(',', $value)));
        $lines = array_map(fn (string $name) => "php_admin_value[extension] = {$name}.so", $names);

        return implode("\n", $lines);
    }

    /**
     * "zend_extensions" é ativado via um php.ini próprio da conta,
     * scaneado antes do diretório padrão da versão (ver
     * buildZendIniLines() e App\Support\PhpFpmPool::applyZendIni()),
     * não via php_admin_value — zend_extension só pode ser setado no
     * boot do processo, nunca por pool. Revalida contra
     * PhpExtensionList::availableZendExtensionsForPerAccountOptIn(),
     * mesmo raciocínio de sanitizeExtraExtensions().
     */
    public static function sanitizeZendExtensions(string $phpVersion, string $value): string
    {
        $requested = array_filter(array_map('trim', explode(',', $value)));
        $available = array_column(PhpExtensionList::availableZendExtensionsForPerAccountOptIn($phpVersion), 'name');
        $allowed = array_values(array_intersect($requested, $available));

        return implode(',', $allowed);
    }

    /**
     * Monta o conteúdo do ini próprio da conta — uma linha
     * "zend_extension={valor real}" por extensão selecionada. Usa o
     * "zend_directive" de PhpExtensionList (o nome real do .so lido do
     * arquivo original), não "{name}.so" — o ioncube, por exemplo, tem
     * sufixo de versão no arquivo (ioncube_loader_lin_8.3.so), que não
     * bate com o "name" amigável ("ioncube"). String vazia se nenhuma
     * extensão selecionada (nesse caso App\Support\PhpFpmPool::applyZendIni()
     * remove o diretório da conta em vez de escrever um ini vazio).
     *
     * Descartei a abordagem anterior (flag "-d zend_extension=..." no
     * ExecStart) depois de confirmar em produção que o ioncube_loader
     * recusa carregar assim — ele exige aparecer como a PRIMEIRA
     * entrada dentro de um php.ini de verdade, e diretivas via -d são
     * processadas tarde demais pro check interno dele. Um diretório de
     * scan próprio da conta, listado ANTES do diretório padrão da
     * versão em PHP_INI_SCAN_DIR, resolve isso sem perder nem duplicar
     * nenhuma configuração do php.ini principal nem do scan padrão.
     */
    private static function buildZendIniLines(string $phpVersion, string $value): string
    {
        $names = array_filter(array_map('trim', explode(',', $value)));

        if (empty($names)) {
            return '';
        }

        $directives = array_column(PhpExtensionList::availableZendExtensionsForPerAccountOptIn($phpVersion), 'zend_directive', 'name');

        $lines = array_filter(array_map(fn (string $name) => isset($directives[$name]) ? "zend_extension={$directives[$name]}" : null, $names));

        return implode("\n", $lines);
    }

    /**
     * @return array<string, string>
     */
    public static function variables(string $username, string $phpVersion, array $overrides = []): array
    {
        $defaults = config('provisioning.default_pool_settings');

        $variables = [
            'username' => $username,
            'socket_path' => PhpFpmPool::socketPath($username, $phpVersion),
            'config_path' => PhpFpmPool::configPath($username),
            'home_dir' => config('provisioning.home_base_dir')."/{$username}",
            'binary' => PhpVersion::config($phpVersion)['binary'],
        ];

        foreach (self::TUNABLE_KEYS as $key) {
            $variables[$key] = $overrides[$key] ?? $defaults[$key];
        }

        $variables['disable_functions'] = self::sanitizeDisableFunctions((string) $variables['disable_functions']);
        $variables['extra_extensions'] = self::sanitizeExtraExtensions($phpVersion, (string) $variables['extra_extensions']);
        $variables['extra_extensions_lines'] = self::renderExtraExtensionsLines($variables['extra_extensions']);
        $variables['zend_extensions'] = self::sanitizeZendExtensions($phpVersion, (string) $variables['zend_extensions']);
        // "zend_ini_lines" é só o CONTEÚDO a escrever (ou não) no ini
        // próprio da conta — quem decide o Environment=PHP_INI_SCAN_DIR
        // do unit é a Action que chama variables(), depois de gravar
        // (ou remover) o arquivo via PhpFpmPool::applyZendIni(). Fica
        // fora do stub .service diretamente, diferente do resto das
        // TUNABLE_KEYS (que vão direto pro placeholder).
        $variables['zend_ini_lines'] = self::buildZendIniLines($phpVersion, $variables['zend_extensions']);

        return $variables;
    }
}
