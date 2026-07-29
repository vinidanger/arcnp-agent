<?php

namespace App\Support;

class PhpFpmPool
{
    public static function socketPath(string $username, string $phpVersion): string
    {
        return PhpVersion::config($phpVersion)['socket_dir']."/{$username}.sock";
    }

    public static function poolConfigPath(string $username, string $phpVersion): string
    {
        return PhpVersion::config($phpVersion)['pool_dir']."/{$username}.conf";
    }
}
