<?php

namespace App\Services\System;

use RuntimeException;

/**
 * Substituição simples de placeholders "{{var}}" em stubs de config
 * (nginx, php-fpm). De propósito não usa Blade aqui — são arquivos de
 * configuração de sistema, não HTML, e a engine do Blade adicionaria
 * comportamento (escaping, diretivas) que não faz sentido para eles.
 */
class TemplateRenderer
{
    public function render(string $stubName, array $variables): string
    {
        $path = resource_path("stubs/{$stubName}.stub");

        if (! is_file($path)) {
            throw new RuntimeException("Stub não encontrado: {$stubName}");
        }

        $replacements = [];

        foreach ($variables as $key => $value) {
            $replacements['{{'.$key.'}}'] = $value;
        }

        return strtr(file_get_contents($path), $replacements);
    }
}
