<?php

namespace App\Support;

/**
 * Cache de página (fastcgi_cache) é opt-in por domínio — mesmo padrão
 * do WafDirectives (placeholder no stub, sem VhostExtraBlock). "Purgar"
 * não apaga arquivo nenhum do disco — incrementa $version, que entra
 * na própria fastcgi_cache_key. As entradas da versão anterior ficam
 * órfãs e são recicladas sozinhas pelo cache manager nativo do nginx
 * (inactive/max_size do fastcgi_cache_path, configurado 1x no
 * nginx.conf — ver seção do deploy/README). Sem isso, purgar exigiria
 * o módulo de terceiro ngx_cache_purge (mais uma compilação arriscada,
 * mesma categoria da do WAF).
 *
 * Regra de bypass fixa (não configurável nesta v1): pula cache em
 * POST, em request com query string, em /wp-admin//wp-login.php/
 * xmlrpc.php, e em qualquer cookie de sessão/comentário do WordPress.
 */
class CacheDirectives
{
    public static function render(bool $enabled, int $version): string
    {
        if (! $enabled) {
            return '';
        }

        $version = max(1, $version);

        $lines = [
            'set $skip_cache 0;',
            'if ($request_method = POST) { set $skip_cache 1; }',
            'if ($query_string != "") { set $skip_cache 1; }',
            'if ($request_uri ~* "/wp-admin/|/wp-login\.php|/xmlrpc\.php") { set $skip_cache 1; }',
            'if ($http_cookie ~* "wordpress_logged_in_|wp-postpass_|comment_author_") { set $skip_cache 1; }',
            '',
            'fastcgi_cache arcnp_cache;',
            'fastcgi_cache_valid 200 60m;',
            'fastcgi_cache_bypass $skip_cache;',
            'fastcgi_no_cache $skip_cache;',
            "fastcgi_cache_key \"{$version}:\$scheme\$request_method\$host\$request_uri\";",
            'add_header X-Cache-Status $upstream_cache_status;',
        ];

        return implode("\n", array_map(fn ($line) => $line === '' ? '' : "        {$line}", $lines));
    }
}
