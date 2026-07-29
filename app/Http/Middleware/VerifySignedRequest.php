<?php

namespace App\Http\Middleware;

use App\Support\RequestSigner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VerifySignedRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('agent.shared_secret');
        abort_if(blank($secret), 500, 'Agent sem shared_secret configurado.');

        $timestamp = $request->header('X-Agent-Timestamp');
        $nonce = $request->header('X-Agent-Nonce');
        $signature = $request->header('X-Agent-Signature');

        abort_if(blank($timestamp) || blank($nonce) || blank($signature), 401, 'Cabeçalhos de assinatura ausentes.');

        $tolerance = config('agent.timestamp_tolerance');
        abort_if(abs(time() - (int) $timestamp) > $tolerance, 401, 'Timestamp fora da janela permitida.');

        $nonceKey = "agent:nonce:{$nonce}";
        abort_if(Cache::has($nonceKey), 401, 'Nonce já utilizado (replay).');

        $expected = RequestSigner::signature(
            $request->method(),
            $request->path(),
            $timestamp,
            $nonce,
            $request->getContent(),
            $secret,
        );

        abort_unless(hash_equals($expected, $signature), 401, 'Assinatura inválida.');

        Cache::put($nonceKey, true, $tolerance * 2);

        return $next($request);
    }
}
