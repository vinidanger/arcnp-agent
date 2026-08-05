<?php

namespace App\Support;

/**
 * 1 processo mestre de PHP-FPM por CONTA (não mais por versão
 * compartilhado entre contas) — ver comentário em
 * config/provisioning.php. Só existe 1 instância ativa por conta a
 * qualquer momento, por isso nada aqui depende de $phpVersion: o
 * socket/config/unit são sempre os mesmos, independente de qual
 * versão está rodando por trás no momento.
 */
class PhpFpmPool
{
    /**
     * $phpVersion aceito mas ignorado — mantido só pra não precisar
     * tocar nos 5 call sites que hoje passam os dois argumentos
     * (CreateVirtualHostAction, CreateAddonDomainAction,
     * UpdateVirtualHostPhpVersionAction, IssueSslCertificateAction,
     * DeleteHostedAppAction), todos só querem o caminho do socket pra
     * montar o fastcgi_pass do vhost.
     */
    public static function socketPath(string $username, string $phpVersion = ''): string
    {
        return "/run/arcnp-php/{$username}.sock";
    }

    public static function configPath(string $username): string
    {
        return config('provisioning.php_fpm_config_dir')."/{$username}.conf";
    }

    public static function serviceName(string $username): string
    {
        return "arcnp-php-{$username}.service";
    }
}
