<?php

namespace App\Support;

use InvalidArgumentException;

class MysqlIdentifier
{
    private const PATTERN = '/^[a-z][a-z0-9_]{2,63}$/';

    /**
     * Nome de banco/usuário MySQL. CREATE DATABASE/CREATE USER não
     * aceitam bind de identificador (só de valor), então validar
     * rigorosamente aqui é a única defesa contra SQL injection nesses
     * comandos — nunca interpolar direto sem passar por isto.
     */
    public static function validate(string $identifier): string
    {
        if (! preg_match(self::PATTERN, $identifier)) {
            throw new InvalidArgumentException("Identificador MySQL inválido: {$identifier}");
        }

        return $identifier;
    }
}
