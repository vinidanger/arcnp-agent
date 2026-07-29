<?php

namespace App\Support;

/**
 * Único ponto que decide o caminho do document root — location=outside
 * usa uma árvore própria (domains/{domain}/public_html, fora de
 * public_html), location=inside (ou ausente, domínio principal) fica
 * dentro de public_html. Centralizado aqui porque três Actions
 * diferentes (criar vhost, emitir SSL, trocar versão de PHP) precisam
 * calcular o mesmo caminho — tinha divergido antes de existir isto.
 */
class DocumentRoot
{
    public static function resolve(string $homeDir, string $domain, ?string $location, ?string $subdir): string
    {
        if ($location === 'outside') {
            return "{$homeDir}/domains/{$domain}/public_html";
        }

        return $subdir ? "{$homeDir}/public_html/{$subdir}" : "{$homeDir}/public_html";
    }
}
