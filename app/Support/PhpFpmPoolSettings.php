<?php

namespace App\Support;

/**
 * Monta as variáveis do stub php-fpm-pool.stub — reaproveitado por
 * CreatePhpFpmPoolAction, SwitchPhpVersionAction e
 * UpdatePhpFpmPoolSettingsAction pra não triplicar o merge de
 * defaults/overrides nos três. $overrides ausente/vazio = usa
 * config('provisioning.default_pool_settings') integralmente.
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
     * @return array<string, string>
     */
    public static function variables(string $username, string $phpVersion, array $overrides = []): array
    {
        $defaults = config('provisioning.default_pool_settings');

        $variables = [
            'username' => $username,
            'socket_path' => PhpFpmPool::socketPath($username, $phpVersion),
            'home_dir' => config('provisioning.home_base_dir')."/{$username}",
        ];

        foreach (self::TUNABLE_KEYS as $key) {
            $variables[$key] = $overrides[$key] ?? $defaults[$key];
        }

        $variables['disable_functions'] = self::sanitizeDisableFunctions((string) $variables['disable_functions']);
        $variables['extra_extensions'] = self::sanitizeExtraExtensions($phpVersion, (string) $variables['extra_extensions']);
        $variables['extra_extensions_lines'] = self::renderExtraExtensionsLines($variables['extra_extensions']);

        return $variables;
    }
}
