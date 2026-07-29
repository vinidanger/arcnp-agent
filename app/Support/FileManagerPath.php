<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * O gerenciador de arquivos fica restrito a UMA raiz por vez — nunca o
 * home inteiro (evita expor backups/, logs/ etc. a navegação/exclusão
 * por engano). Raiz padrão é public_html; $root (quando informado) é
 * um domínio com árvore própria fora de public_html
 * (domains/{root}/public_html — ver location=outside_public_html).
 * Toda resolução passa por realpath() e confere que o resultado
 * continua DENTRO da base, contra symlink e ../ escape — a validação
 * de string sozinha (bloquear "..") não basta porque um symlink dentro
 * da raiz poderia apontar pra fora.
 */
class FileManagerPath
{
    public static function baseDir(string $username, ?string $root = null): string
    {
        $home = config('provisioning.home_base_dir')."/{$username}";

        $candidate = blank($root)
            ? "{$home}/public_html"
            : "{$home}/domains/".DomainName::validate($root).'/public_html';

        $base = realpath($candidate);

        if ($base === false) {
            throw new InvalidArgumentException('Diretório não encontrado.');
        }

        return $base;
    }

    /**
     * Pra caminhos que já existem (listar, ler, apagar, origem de
     * rename) — realpath resolve o caminho real (inclusive symlinks).
     */
    public static function resolveExisting(string $username, string $relativePath, ?string $root = null): string
    {
        $base = self::baseDir($username, $root);
        $candidate = self::join($base, $relativePath);

        $real = realpath($candidate);

        if ($real === false || ! self::isWithin($real, $base)) {
            throw new InvalidArgumentException('Caminho inválido.');
        }

        return $real;
    }

    /**
     * Pra caminhos que ainda NÃO existem (criar arquivo/pasta, destino
     * de rename) — resolve o diretório PAI (que precisa existir) e
     * junta o nome do arquivo/pasta novo nele.
     */
    public static function resolveForCreate(string $username, string $relativePath, ?string $root = null): string
    {
        $base = self::baseDir($username, $root);
        $candidate = self::join($base, $relativePath);

        $parentReal = realpath(dirname($candidate));

        if ($parentReal === false || ! self::isWithin($parentReal, $base)) {
            throw new InvalidArgumentException('Diretório pai inválido.');
        }

        return $parentReal.'/'.basename($candidate);
    }

    private static function join(string $base, string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');

        if ($relativePath === '') {
            return $base;
        }

        if (str_contains($relativePath, "\0") || preg_match('#(^|/)\.\.(/|$)#', $relativePath)) {
            throw new InvalidArgumentException('Caminho inválido.');
        }

        return $base.'/'.$relativePath;
    }

    private static function isWithin(string $real, string $base): bool
    {
        return $real === $base || str_starts_with($real, $base.'/');
    }
}
