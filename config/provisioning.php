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
     * binary/ini_dir continuam por versão (é o que aponta pro binário
     * certo no ExecStart e pro diretório de extensões certo). 8.1/8.2/
     * 8.4 vêm do Remi como Software Collections (SCL); ini_dir/binary
     * são INFERIDOS pela mesma convenção Remi, NUNCA verificados contra
     * uma VPS real (esse ambiente de dev não tem Remi/RHEL). Confirme
     * no primeiro deploy: `{binary} --ini` e confira a linha "Scan for
     * additional .ini files in" bate com ini_dir configurado aqui antes
     * de confiar na feature.
     */
    'php_versions' => [
        '8.1' => [
            'ini_dir' => '/etc/opt/remi/php81/php.d',
            'binary' => '/usr/bin/php81',
        ],
        '8.2' => [
            'ini_dir' => '/etc/opt/remi/php82/php.d',
            'binary' => '/usr/bin/php82',
        ],
        '8.3' => [
            'ini_dir' => '/etc/php.d',
            'binary' => '/usr/bin/php',
        ],
        '8.4' => [
            'ini_dir' => '/etc/opt/remi/php84/php.d',
            'binary' => '/usr/bin/php84',
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
