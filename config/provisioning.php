<?php

return [
    /*
     * Layout de diretórios usado pelas Actions de provisionamento.
     * Convenção RHEL-family (AlmaLinux/Rocky) — ver deploy/README.md.
     */
    'home_base_dir' => '/home',
    'nginx_conf_dir' => '/etc/nginx/conf.d',
    'htpasswd_dir' => '/etc/nginx/htpasswd',
    'ftp_virtual_users_dir' => '/etc/vsftpd/virtual_users',
    'ftp_user_conf_dir' => '/etc/vsftpd/user_conf',
    'default_php_version' => env('AGENT_DEFAULT_PHP_VERSION', '8.3'),

    /*
     * Ponto de montagem do filesystem que hospeda os home dirs das
     * contas — precisa estar montado com quota de usuário habilitada
     * (usrquota/ext4 ou uquota/XFS, ver seção de cota de disco do
     * deploy/README.md). "set-disk-quota.sh" usa isso como alvo do
     * "setquota -u".
     */
    'disk_quota_mount' => env('AGENT_DISK_QUOTA_MOUNT', '/home'),

    /*
     * Diretório dos arquivos de config combinados (global+pool) de cada
     * conta — um arquivo por conta, nome = username. Agent-writable
     * direto (sem sudo), mesmo padrão do nginx_conf_dir: só o unit
     * systemd em si (que referencia esse arquivo) passa por script sudo.
     */
    'php_fpm_config_dir' => '/etc/arcnp-php',

    /*
     * Cada CONTA roda seu próprio processo mestre de PHP-FPM (unit
     * systemd "arcnp-php-{username}.service", ver App\Support\PhpFpmPool)
     * — não mais um service compartilhado por versão. É o que permite
     * o cgroup da conta (user-{uid}.slice, ver PhpFpmPoolSettings/
     * resources.set_limits) realmente limitar CPU/RAM do PHP: os
     * workers do FPM são filhos do processo mestre e herdam o cgroup
     * dele automaticamente ao nascer.
     *
     * binary = binário do DAEMON php-fpm (não o CLI!) — é o que entra
     * no ExecStart do unit dedicado da conta. Confirmado numa VPS real
     * via `systemctl cat php81-php-fpm.service` (etc.) antes de existir
     * o service por conta — Remi usa Software Collections de verdade
     * (root próprio em /opt/remi/php$v/root/...), não o padrão
     * "/usr/bin/php$v" que eu tinha assumido inicialmente (esse é só o
     * CLI, `--fpm-config` não é opção dele, causa crash-loop).
     *
     * ini_dir ainda é INFERIDO (não confirmado) — dado que o binário
     * real mora em /opt/remi/php$v/root/..., o ini_dir provavelmente
     * também está sob esse prefixo (/opt/remi/php$v/root/etc/php.d ou
     * similar), não /etc/opt/remi/php$v/php.d como está abaixo. Só
     * afeta a tela de Extensões PHP (por servidor), não o service por
     * conta. Confirme com `php81 --ini` (ou o binário CLI equivalente)
     * antes de confiar na tela de extensões pras versões 8.1/8.2/8.4.
     */
    'php_versions' => [
        '8.1' => [
            'ini_dir' => '/etc/opt/remi/php81/php.d',
            'binary' => '/opt/remi/php81/root/usr/sbin/php-fpm',
        ],
        '8.2' => [
            'ini_dir' => '/etc/opt/remi/php82/php.d',
            'binary' => '/opt/remi/php82/root/usr/sbin/php-fpm',
        ],
        '8.3' => [
            'ini_dir' => '/etc/php.d',
            'binary' => '/usr/sbin/php-fpm',
        ],
        '8.4' => [
            'ini_dir' => '/etc/opt/remi/php84/php.d',
            'binary' => '/opt/remi/php84/root/usr/sbin/php-fpm',
        ],
    ],

    /*
     * E-mail usado no registro da conta Let's Encrypt (avisos de
     * expiração/problema de renovação — não é por domínio, é fixo).
     */
    'ssl_admin_email' => env('AGENT_SSL_ADMIN_EMAIL'),

    /*
     * Valores usados no pool PHP-FPM de uma conta quando ela não tem
     * ajuste próprio salvo (php_fpm_settings nulo no Painel) — tanto na
     * criação quanto numa troca de versão de PHP. Editável por conta via
     * Admin/Cliente > "Configurações de PHP" (ação php.update_pool_settings).
     */
    'default_pool_settings' => [
        'memory_limit' => '128M',
        'upload_max_filesize' => '64M',
        'post_max_size' => '64M',
        'max_execution_time' => '30',
        'max_input_time' => '60',
        'max_input_vars' => '1000',
        'max_file_uploads' => '20',
        'session.gc_maxlifetime' => '1440',
        'display_errors' => 'Off',
        'log_errors' => 'On',
        'error_reporting' => 'E_ALL & ~E_DEPRECATED & ~E_NOTICE',
        'file_uploads' => 'On',
        'short_open_tag' => 'Off',
        'disable_functions' => '',
        'extra_extensions' => '',
    ],
];
