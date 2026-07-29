<?php

namespace App\Support;

class NginxVhost
{
    public static function configPath(string $domain): string
    {
        return config('provisioning.nginx_conf_dir')."/{$domain}.conf";
    }
}
