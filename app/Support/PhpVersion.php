<?php

namespace App\Support;

use InvalidArgumentException;

class PhpVersion
{
    /**
     * @return array{pool_dir: string, socket_dir: string, service: string}
     */
    public static function config(string $version): array
    {
        $versions = config('provisioning.php_versions');

        if (! isset($versions[$version])) {
            throw new InvalidArgumentException("Versão de PHP não suportada: {$version}");
        }

        return $versions[$version];
    }
}
