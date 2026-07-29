<?php

namespace App\Support;

/**
 * Monta o arquivo de inclusão /etc/named/zones.conf — uma stanza
 * "zone {...};" por domínio ativo nesse servidor. O Painel sempre
 * reenvia a lista COMPLETA (nunca edição incremental), então isto
 * sempre reescreve o arquivo inteiro a partir do zero.
 */
class DnsZonesConf
{
    /**
     * @param  list<string>  $domains
     */
    public static function render(array $domains): string
    {
        $blocks = array_map(function (string $domain) {
            $domain = DomainName::validate($domain);

            return "zone \"{$domain}\" {\n    type master;\n    file \"/etc/named/zones/{$domain}.zone\";\n    allow-update { none; };\n};";
        }, $domains);

        return implode("\n\n", $blocks)."\n";
    }
}
