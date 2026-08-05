<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

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

    /**
     * Diretório de scan de ini PRÓPRIO da conta, usado só quando ela
     * tem zend_extension habilitada (ver PhpFpmPoolSettings). Fica
     * dentro do mesmo diretório que o Agent já escreve sem sudo
     * (php_fpm_config_dir), então criar/remover isso também não precisa
     * de privilégio novo.
     */
    public static function zendIniDir(string $username): string
    {
        return config('provisioning.php_fpm_config_dir')."/{$username}-zend.d";
    }

    /**
     * Escreve (ou remove, se $lines vier vazio) o ini de zend_extension
     * dessa conta dentro do diretório próprio dela. Devolve o caminho do
     * diretório quando escreveu algo, ou string vazia quando não há
     * zend_extension pra essa conta (uso: montar o Environment=
     * PHP_INI_SCAN_DIR do unit, ver as Actions que chamam isso).
     *
     * Importante: o nome do arquivo ("00-zend.ini") precisa ordenar
     * ANTES de qualquer coisa no diretório PADRÃO da versão (ver
     * PHP_INI_SCAN_DIR montado nas Actions) — é isso que garante que um
     * zend_extension exigente sobre ordem (caso do ioncube_loader, que
     * recusa carregar se não for "a primeira entrada") funcione mesmo
     * quando a conta tem várias linhas aqui.
     */
    public static function applyZendIni(string $username, string $lines): string
    {
        $dir = self::zendIniDir($username);

        if ($lines === '') {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }

            return '';
        }

        File::ensureDirectoryExists($dir);
        File::put("{$dir}/00-zend.ini", $lines."\n");

        return $dir;
    }
}
