<?php

return [
    /*
     * Layout de diretórios/sockets usado pelas Actions de provisionamento.
     * Convenção RHEL-family (AlmaLinux/Rocky) — ver deploy/README.md.
     *
     * Pools de contas de hospedagem ficam num php-fpm.service SEPARADO
     * do Painel/Agent (php-fpm-hosting.service) — reload de pool de
     * cliente nunca deve derrubar/reiniciar o Painel ou o próprio Agent,
     * já que eles rodam no mesmo host.
     */
    'home_base_dir' => '/home',
    'nginx_conf_dir' => '/etc/nginx/conf.d',
    'php_fpm_pool_dir' => '/etc/php-fpm-hosting.d',
    'php_fpm_socket_dir' => '/run/php-fpm-hosting',
    'php_fpm_service' => 'php-fpm-hosting',
    'default_php_version' => env('AGENT_DEFAULT_PHP_VERSION', '8.3'),
];
