<?php

namespace App\Support;

class PhpFpmPool
{
    public static function socketPath(string $username): string
    {
        return config('provisioning.php_fpm_socket_dir')."/{$username}.sock";
    }

    public static function poolConfigPath(string $username): string
    {
        return config('provisioning.php_fpm_pool_dir')."/{$username}.conf";
    }
}
