<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Mesma defesa de App\Support\FileManagerPath (realpath() + checagem
 * de prefixo, contra ../ e symlink escape) mas ancorada na home da
 * conta INTEIRA, não forçada em public_html — uma conta FTP pode
 * apontar pra qualquer subpasta da conta (ou a conta inteira, se
 * $relativePath vier vazio). Propositalmente uma classe separada:
 * FileManagerPath já serve o gerenciador de arquivos em produção, não
 * vale o risco de alterar essa raiz fixa por causa de uma feature
 * não-relacionada.
 *
 * O caminho precisa já EXISTIR — não cria nada aqui (mesma regra do
 * resolveExisting() do FileManagerPath). Se a conta quer expor uma
 * pasta nova, cria ela pelo gerenciador de arquivos primeiro.
 */
class FtpChrootPath
{
    public static function resolveExisting(string $username, string $relativePath): string
    {
        $home = config('provisioning.home_base_dir')."/{$username}";
        $base = realpath($home);

        if ($base === false) {
            throw new InvalidArgumentException('Home do usuário não encontrada.');
        }

        $candidate = self::join($base, $relativePath);
        $real = realpath($candidate);

        if ($real === false || ! self::isWithin($real, $base)) {
            throw new InvalidArgumentException('Caminho de FTP inválido.');
        }

        return $real;
    }

    private static function join(string $base, string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');

        if ($relativePath === '') {
            return $base;
        }

        if (str_contains($relativePath, "\0") || preg_match('#(^|/)\.\.(/|$)#', $relativePath)) {
            throw new InvalidArgumentException('Caminho de FTP inválido.');
        }

        return $base.'/'.$relativePath;
    }

    private static function isWithin(string $real, string $base): bool
    {
        return $real === $base || str_starts_with($real, $base.'/');
    }
}
