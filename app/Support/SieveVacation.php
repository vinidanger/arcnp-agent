<?php

namespace App\Support;

/**
 * Monta o script Sieve de "aviso de férias" (RFC 5230) por caixa — cada
 * caixa habilitada ganha seu próprio .dovecot.sieve, compilado com
 * sievec (ver manage-mail.sh). :days 1 é o intervalo mínimo entre
 * respostas automáticas pro MESMO remetente, evita ping-pong de
 * autoresposta quando duas caixas com férias ativa trocam e-mail entre si.
 */
class SieveVacation
{
    public static function render(string $subject, string $message): string
    {
        $subject = self::escapeQuoted($subject);
        $message = self::dotStuff(str_replace("\r\n", "\n", trim($message)));

        return <<<SIEVE
        require ["vacation"];

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
