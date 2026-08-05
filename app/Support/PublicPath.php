<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Mesma ideia do Subdirectory, mas aceita caminho aninhado (ex.:
 * "public", "backend/public") — Subdirectory fica restrito a um
 * segmento só de propósito (usado pra decidir em qual pasta um domínio
 * adicional mora, não faz sentido aninhado); public_path é uma
 * subpasta DENTRO de uma raiz já resolvida, onde aninhamento é legítimo
 * (alguns projetos têm o public/ mais fundo que um nível).
 */
class PublicPath
{
    private const PATTERN = '/^[a-z0-9][a-z0-9_-]*(\/[a-z0-9][a-z0-9_-]*)*$/';

    private const MAX_LENGTH = 255;

    public static function validate(string $path): string
    {
        if (strlen($path) > self::MAX_LENGTH || ! preg_match(self::PATTERN, $path)) {
            throw new InvalidArgumentException("Diretório público inválido: {$path}");
        }

        return $path;
    }
}
