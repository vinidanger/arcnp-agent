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
    /**
     * $publicPath é a pasta DENTRO da raiz normal que o nginx deve
     * servir de fato — existe pra apps tipo Laravel/Symfony, cujo index
     * real fica em public/, não na raiz do projeto (ver Subdirectory,
     * mesma validação é reaproveitada pra esse campo).
     */
    public static function resolve(string $homeDir, string $domain, ?string $location, ?string $subdir, ?string $publicPath = null): string
    {
        $base = $location === 'outside'
            ? "{$homeDir}/domains/{$domain}/public_html"
            : ($subdir ? "{$homeDir}/public_html/{$subdir}" : "{$homeDir}/public_html");

        return $publicPath ? "{$base}/{$publicPath}" : $base;
    }
}
