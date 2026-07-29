<?php

namespace App\Support;

use InvalidArgumentException;

class LinuxUsername
{
    private const PATTERN = '/^[a-z][a-z0-9]{2,31}$/';

    /**
     * Valida e devolve o username. Chamado em toda Action antes de
     * qualquer uso em comando de sistema — mesmo o script sudo também
     * revalidando (defesa em profundidade, nunca confiar só numa camada).
     */
    public static function validate(string $username): string
    {
        if (! preg_match(self::PATTERN, $username)) {
            throw new InvalidArgumentException("Username inválido: {$username}");
        }

        return $username;
    }
}
