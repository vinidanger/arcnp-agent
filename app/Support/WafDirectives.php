<?php

namespace App\Support;

/**
 * WAF (ModSecurity + OWASP CRS) é opt-in por domínio — ver seção 47 do
 * deploy/README.md. Só as 2 diretivas que ligam o módulo pro vhost;
 * toda a config real (regras, modo DetectionOnly/On) mora em
 * /etc/nginx/modsecurity/modsecurity.conf, fora do controle do Painel
 * de propósito (trocar de DetectionOnly pra On é decisão manual do
 * admin do servidor, não do painel).
 */
class WafDirectives
{
    public static function render(bool $enabled): string
    {
        if (! $enabled) {
            return '';
        }

        return "    modsecurity on;\n    modsecurity_rules_file /etc/nginx/modsecurity/modsecurity.conf;";
    }
}
