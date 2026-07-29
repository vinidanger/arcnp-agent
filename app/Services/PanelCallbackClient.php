<?php

namespace App\Services;

use App\Support\RequestSigner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Envia requisições assinadas do Agent para o Painel (callback de job
 * assíncrono, heartbeat). Best-effort: falha é logada, nunca derruba
 * quem chamou — o Painel tem outros meios de perceber a ausência
 * (polling de job, heartbeat atrasado).
 */
class PanelCallbackClient
{
    public function sendJobCallback(array $body): bool
    {
        return $this->post('callback', $body);
    }

    public function sendHeartbeat(array $metrics): bool
    {
        return $this->post('heartbeat', $metrics);
    }

    private function post(string $endpoint, array $body): bool
    {
        $baseUrl = config('agent.panel_base_url');
        $serverId = config('agent.server_id');

        if (blank($baseUrl) || blank($serverId)) {
            Log::warning("agent.panel_base_url ou server_id não configurados; {$endpoint} não enviado.", $body);

            return false;
        }

        $path = "agent-webhooks/{$serverId}/{$endpoint}";
        $secret = config('agent.shared_secret');
        $timestamp = (string) time();
        $nonce = (string) Str::uuid();
        $json = json_encode($body);

        $signature = RequestSigner::signature('POST', $path, $timestamp, $nonce, $json, $secret);

        try {
            $response = Http::withHeaders([
                'X-Agent-Timestamp' => $timestamp,
                'X-Agent-Nonce' => $nonce,
                'X-Agent-Signature' => $signature,
                'Content-Type' => 'application/json',
            ])->timeout(10)->withBody($json, 'application/json')->post("{$baseUrl}/{$path}");

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error("Falha ao enviar {$endpoint} ao Painel: ".$e->getMessage(), $body);

            return false;
        }
    }
}
