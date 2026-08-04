<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Uma versão só por runtime (sem seleção por app, ao contrário do PHP)
 * — "atalho simplificado" de propósito, mesmo espírito do resto da
 * Fase 1/2. Node vem do módulo AppStream do EL9 (dnf module install
 * nodejs:20), Python é o python3 que o próprio sistema já traz.
 */
class AppRuntime
{
    private const BINARIES = [
        'node' => '/usr/bin/node',
        'python' => '/usr/bin/python3',
    ];

    public static function binary(string $runtime): string
    {
        if (! isset(self::BINARIES[$runtime])) {
            throw new InvalidArgumentException("Runtime inválido: {$runtime}");
        }

        return self::BINARIES[$runtime];
    }
}
