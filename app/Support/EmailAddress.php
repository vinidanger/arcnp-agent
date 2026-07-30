<?php

namespace App\Support;

use InvalidArgumentException;

class EmailAddress
{
    private const LOCAL_PART_PATTERN = '/^[a-z0-9]([a-z0-9._-]{0,63}[a-z0-9])?$/';

    /**
     * Só a parte local (antes do @) — o domínio já é validado
     * separadamente via DomainName::validate() em quem chama, porque
     * ele também precisa bater com uma zona/domínio já cadastrado.
     */
    public static function validateLocalPart(string $localPart): string
    {
        $localPart = strtolower($localPart);

        if (! preg_match(self::LOCAL_PART_PATTERN, $localPart)) {
            throw new InvalidArgumentException("Nome de caixa de e-mail inválido: {$localPart}");
        }

        return $localPart;
    }

    public static function build(string $localPart, string $domain): string
    {
        return self::validateLocalPart($localPart).'@'.DomainName::validate($domain);
    }
}
