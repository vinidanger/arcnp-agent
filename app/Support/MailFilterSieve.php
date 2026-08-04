<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Monta um bloco de regra Sieve (um "if header ..." por filtro) — sem
 * a linha "require", igual SieveVacation::renderBlock(). "stop;" ao
 * final garante que só a PRIMEIRA regra que casar age na mensagem
 * (evita fileinto duplicado se duas regras casarem a mesma mensagem).
 */
class MailFilterSieve
{
    public static function renderBlock(string $field, string $value, string $action, ?string $folder): string
    {
        $fieldEsc = self::escapeQuoted($field);
        $valueEsc = self::escapeQuoted($value);

        $actionLine = match ($action) {
            'discard' => 'discard;',
            // :create garante que a pasta é criada automaticamente se
            // ainda não existir — sem isso, um nome de pasta digitado
            // errado faria a regra falhar silenciosamente.
            'move_to_folder' => 'fileinto :create "'.self::escapeQuoted((string) $folder).'";',
            default => throw new InvalidArgumentException("Ação de filtro inválida: {$action}"),
        };

        return <<<SIEVE
        if header :contains "{$fieldEsc}" "{$valueEsc}" {
            {$actionLine}
            stop;
        }
        SIEVE;
    }

    private static function escapeQuoted(string $text): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $text);
    }
}
