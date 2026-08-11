<?php

namespace App\Support;

/**
 * HTTP/3 (QUIC) — flag por SERVIDOR, não por domínio (diferente do
 * WAF/cache): é melhoria pura de transporte, sem downside de
 * compatibilidade (cliente sem suporte a QUIC cai pra HTTP/2 sozinho),
 * então não faz sentido UX ter opt-in por site. O admin só liga
 * `servers.http3_enabled` DEPOIS de confirmar manualmente que o
 * binário do nginx desse servidor já suporta QUIC (troca de binário +
 * lib TLS, ver seção 52 do deploy/README.md — bem mais arriscado que
 * o WAF, que só precisou de um módulo dinâmico a mais).
 */
class Http3Directives
{
    public static function render(bool $enabled): string
    {
        if (! $enabled) {
            return '';
        }

        return "    listen 443 quic reuseport;\n    listen [::]:443 quic reuseport;\n    add_header Alt-Svc 'h3=\":443\"; ma=86400';";
    }
}
