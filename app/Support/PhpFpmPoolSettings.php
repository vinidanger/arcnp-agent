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
    ];

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

        return $variables;
    }
}
