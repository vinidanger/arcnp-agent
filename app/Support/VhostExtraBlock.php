<?php

namespace App\Support;

use RuntimeException;

/**
 * Reescreve só o trecho entre um par de marcadores dentro de um vhost
 * nginx já existente — usado por toda Action que precisa injetar um
 * bloco de "location"s extra num domínio (proteção de pasta,
 * redirecionamentos, hotlink) sem regenerar o arquivo inteiro a partir
 * do stub. Se os marcadores ainda não existirem (vhost criado ou
 * regenerado do zero por CreateVirtualHostAction/
 * UpdateVirtualHostPhpVersionAction/IssueSslCertificateAction, que não
 * sabem nada sobre esses blocos extra), insere um bloco novo antes do
 * último "}" do arquivo — auto-cura, sem depender de migrar os stubs.
 */
class VhostExtraBlock
{
    public static function replace(string $contents, string $beginMarker, string $endMarker, string $block): string
    {
        $hasMarkers = str_contains($contents, $beginMarker);

        if ($hasMarkers) {
            $pattern = '/\s*'.preg_quote($beginMarker, '/').'.*?'.preg_quote($endMarker, '/').'/s';
            $replacement = $block === '' ? '' : "\n    {$beginMarker}\n{$block}\n    {$endMarker}";

            return preg_replace($pattern, $replacement, $contents, 1);
        }

        if ($block === '') {
            return $contents;
        }

        $lastBrace = strrpos($contents, '}');

        if ($lastBrace === false) {
            throw new RuntimeException('Vhost em formato inesperado — sem chave de fechamento.');
        }

        $insert = "    {$beginMarker}\n{$block}\n    {$endMarker}\n";

        return substr($contents, 0, $lastBrace).$insert.substr($contents, $lastBrace);
    }
}
