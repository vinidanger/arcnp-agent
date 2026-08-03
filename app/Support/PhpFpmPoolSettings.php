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
     * @return array<string, string>
     */
    public static function variables(string $username, string $phpVersion, array $overrides = []): array
    {
        $defaults = config('provisioning.default_pool_settings');

        return [
            'username' => $username,
            'socket_path' => PhpFpmPool::socketPath($username, $phpVersion),
            'home_dir' => config('provisioning.home_base_dir')."/{$username}",
            'memory_limit' => $overrides['memory_limit'] ?? $defaults['memory_limit'],
            'upload_max_filesize' => $overrides['upload_max_filesize'] ?? $defaults['upload_max_filesize'],
            'post_max_size' => $overrides['post_max_size'] ?? $defaults['post_max_size'],
            'max_execution_time' => (string) ($overrides['max_execution_time'] ?? $defaults['max_execution_time']),
            'short_open_tag' => $overrides['short_open_tag'] ?? $defaults['short_open_tag'],
        ];
    }
}
