<?php

namespace App\Support;

class RequestSigner
{
    /**
     * Mesmo esquema usado nos dois sentidos (Painel -> Agent e Agent -> Painel):
     * HMAC-SHA256 sobre "{method}\n{path}\n{timestamp}\n{nonce}\n{body}".
     */
    public static function signature(string $method, string $path, string $timestamp, string $nonce, string $body, string $secret): string
    {
        $payload = strtoupper($method)."\n".$path."\n".$timestamp."\n".$nonce."\n".$body;

        return hash_hmac('sha256', $payload, $secret);
    }
}
