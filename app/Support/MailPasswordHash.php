<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * O Painel manda o hash já pronto (nunca senha em texto puro) — o
 * Dovecot lê o hash direto do arquivo passwd-file, não precisa
 * recriptografar nada aqui. Só valida que não quebra o formato do
 * arquivo (":" é o separador de campo, quebra de linha quebra o
 * registro inteiro).
 */
class MailPasswordHash
{
    public static function validate(string $hash): string
    {
        if ($hash === '' || str_contains($hash, ':') || str_contains($hash, "\n") || str_contains($hash, "\r")) {
            throw new InvalidArgumentException('Hash de senha de e-mail inválido.');
        }

        return $hash;
    }
}
