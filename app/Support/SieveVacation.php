<?php

namespace App\Support;

/**
 * Monta só o BLOCO "vacation" (RFC 5230), sem a linha "require" — quem
 * monta o script inteiro por caixa é App\Support\MailboxSieveScript,
 * que combina esse bloco com os de MailFilterSieve (um script só por
 * caixa, já que o Dovecot só lê um .dovecot.sieve por vez). :days 1 é
 * o intervalo mínimo entre respostas automáticas pro MESMO remetente,
 * evita ping-pong de autoresposta quando duas caixas com férias ativa
 * trocam e-mail entre si.
 */
class SieveVacation
{
    public static function renderBlock(string $subject, string $message): string
    {
        $subject = self::escapeQuoted($subject);
        $message = self::dotStuff(str_replace("\r\n", "\n", trim($message)));

        return <<<SIEVE
        if true {
            vacation
                :subject "{$subject}"
                :days 1
                text:
        {$message}
        .
                ;
        }
        SIEVE;
    }

    private static function escapeQuoted(string $text): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $text);
    }

    /**
     * RFC 5228 §2.6.2: dentro de um literal "text:", uma linha que
     * começa com "." precisa virar ".." — senão o parser Sieve entende
     * como o fim do literal no meio da mensagem.
     */
    private static function dotStuff(string $text): string
    {
        return implode("\n", array_map(
            fn ($line) => str_starts_with($line, '.') ? '.'.$line : $line,
            explode("\n", $text)
        ));
    }
}
