<?php

namespace App\Support;

use InvalidArgumentException;

class DomainName
{
    private const PATTERN = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    public static function validate(string $domain): string
    {
        if (! preg_match(self::PATTERN, strtolower($domain))) {
            throw new InvalidArgumentException("Domínio inválido: {$domain}");
        }

        return strtolower($domain);
    }
}
