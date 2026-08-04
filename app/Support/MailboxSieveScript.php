<?php

namespace App\Support;

/**
 * Um script Sieve só por caixa — o Dovecot lê um único .dovecot.sieve
 * por vez (ver manage-mail.sh), então filtros e aviso de férias
 * precisam virar UM script combinado, não dois independentes. Filtros
 * vêm primeiro (cada um com "stop;" assim que casar) e o aviso de
 * férias por último — se um filtro já descartou/moveu a mensagem
 * (spam, por exemplo), o "stop;" impede que o aviso de férias dispare
 * em cima dela.
 */
class MailboxSieveScript
{
    /**
     * @param  list<array{field: string, value: string, action: string, folder: ?string}>  $filters
     * @param  array{subject: string, message: string}|null  $vacation
     */
    public static function render(array $filters, ?array $vacation): string
    {
        $requires = [];
        $blocks = [];

        if ($filters !== []) {
            $requires[] = 'fileinto';
            $requires[] = 'mailbox';

            foreach ($filters as $filter) {
                $blocks[] = MailFilterSieve::renderBlock(
                    $filter['field'],
                    $filter['value'],
                    $filter['action'],
                    $filter['folder'] ?? null
                );
            }
        }

        if ($vacation !== null) {
            $requires[] = 'vacation';
            $blocks[] = SieveVacation::renderBlock($vacation['subject'], $vacation['message']);
        }

        if ($blocks === []) {
            return '';
        }

        $requireLine = 'require ['.implode(', ', array_map(fn ($r) => "\"{$r}\"", array_unique($requires))).'];';

        return $requireLine."\n\n".implode("\n\n", $blocks)."\n";
    }
}
