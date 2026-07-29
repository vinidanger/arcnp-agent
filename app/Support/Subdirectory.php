<?php

namespace App\Support;

use InvalidArgumentException;

class Subdirectory
{
    private const PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    public static function validate(string $subdir): string
    {
        if (! preg_match(self::PATTERN, $subdir)) {
            throw new InvalidArgumentException("Subdiretório inválido: {$subdir}");
        }

        return $subdir;
    }
}
