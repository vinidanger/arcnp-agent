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
     * Cada versão de PHP roda como um php-fpm.service SEPARADO — mesmo
     * motivo do 8.3 já ser isolado do Painel/Agent (php-fpm-hosting):
     * reload de pool de uma conta nunca pode afetar contas de OUTRAS
     * versões, e trocar/criar pool de uma versão não reinicia as demais.
     * 8.1/8.2/8.4 vêm do Remi como Software Collections (SCL), instalados
     * lado a lado do PHP padrão do sistema — cada um já traz seu próprio
     * systemd service e diretório de pool prontos, só reaproveitamos.
     */
    /*
     * ini_dir/binary: usados só pelo gerenciamento de extensões
     * (php.list_extensions/php.toggle_extension). Inferidos a partir da
     * mesma convenção Remi já usada pro pool_dir (/etc/opt/remi/php{v}/)
     * — NUNCA verificados contra uma VPS real (esse ambiente de dev não
     * tem Remi/RHEL). Confirme no primeiro deploy: `{binary} --ini` e
     * confira a linha "Scan for additional .ini files in" bate com
     * ini_dir configurado aqui antes de confiar na feature.
     */
    'php_versions' => [
        '8.1' => [
            'pool_dir' => '/etc/opt/remi/php81/php-fpm.d',
            'socket_dir' => '/run/php81-fpm',
            'service' => 'php81-php-fpm',
            'ini_dir' => '/etc/opt/remi/php81/php.d',
            'binary' => '/usr/bin/php81',
        ],
        '8.2' => [
            'pool_dir' => '/etc/opt/remi/php82/php-fpm.d',
            'socket_dir' => '/run/php82-fpm',
            'service' => 'php82-php-fpm',
            'ini_dir' => '/etc/opt/remi/php82/php.d',
            'binary' => '/usr/bin/php82',
        ],
        '8.3' => [
            'pool_dir' => '/etc/php-fpm-hosting.d',
            'socket_dir' => '/run/php-fpm-hosting',
            'service' => 'php-fpm-hosting',
            'ini_dir' => '/etc/php.d',
            'binary' => '/usr/bin/php',
        ],
        '8.4' => [
            'pool_dir' => '/etc/opt/remi/php84/php-fpm.d',
            'socket_dir' => '/run/php84-fpm',
            'service' => 'php84-php-fpm',
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
