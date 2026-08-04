<?php

namespace App\Support;

class HostedAppUnit
{
    public static function name(int $appId): string
    {
        return "arcnp-app-{$appId}.service";
    }

    public static function path(int $appId): string
    {
        return '/etc/systemd/system/'.self::name($appId);
    }
}
