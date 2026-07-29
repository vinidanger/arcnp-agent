<?php

namespace App\Support;

use InvalidArgumentException;

class SshPublicKey
{
    private const PATTERN = '/^(ssh-rsa|ssh-ed25519|ecdsa-sha2-nistp256|ecdsa-sha2-nistp384|ecdsa-sha2-nistp521) [A-Za-z0-9+\/]+=*( .*)?$/';

    public static function validate(string $key): string
    {
        $key = trim($key);

        if ($key === '' || str_contains($key, "\n") || str_contains($key, "\r")) {
            throw new InvalidArgumentException('Chave SSH inválida.');
        }

        if (! preg_match(self::PATTERN, $key)) {
            throw new InvalidArgumentException('Formato de chave SSH pública não reconhecido.');
        }

        return $key;
    }
}
