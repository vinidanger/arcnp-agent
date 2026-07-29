<?php

return [
    /*
     * Layout de diretórios/sockets usado pelas Actions de provisionamento.
     * Convenção RHEL-family (AlmaLinux/Rocky) — ver deploy/README.md.
     */
    'home_base_dir' => '/home',
    'nginx_conf_dir' => '/etc/nginx/conf.d',
    'php_fpm_pool_dir' => '/etc/php-fpm.d',
    'php_fpm_socket_dir' => '/run/php-fpm',
    'default_php_version' => env('AGENT_DEFAULT_PHP_VERSION', '8.3'),
];
