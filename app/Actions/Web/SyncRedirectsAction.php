<?php

namespace App\Actions\Web;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\DomainName;
use App\Support\NginxVhost;
use App\Support\VhostExtraBlock;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

/**
 * Mesmo mecanismo de bloco-entre-marcadores da proteção de pasta (ver
 * VhostExtraBlock/SyncFolderProtectionsAction) — só muda o que entra
 * no bloco: "return {código} {destino};" em vez de "auth_basic".
 */
class SyncRedirectsAction implements AgentAction
{
    private const MARKER_BEGIN = '# ARCNP:REDIRECTS:BEGIN';

    private const MARKER_END = '# ARCNP:REDIRECTS:END';

    public function __construct(private ProcessRunner $processRunner)
    {
    }

    public function isAsync(): bool
    {
        return false;
    }

    public function execute(array $payload): array
    {
        $domain = DomainName::validate($payload['domain'] ?? '');
        $configPath = NginxVhost::configPath($domain);

        if (! File::exists($configPath)) {
            throw new RuntimeException("Vhost não encontrado para: {$domain}");
        }

        $original = File::get($configPath);
        $hasMarkers = str_contains($original, self::MARKER_BEGIN);

        $redirects = array_map(fn (array $r) => $this->validateRedirect($r), $payload['redirects'] ?? []);

        if ($redirects === [] && ! $hasMarkers) {
            return ['domain' => $domain, 'redirects' => 0];
        }

        $updated = VhostExtraBlock::replace($original, self::MARKER_BEGIN, self::MARKER_END, $this->renderBlock($redirects));

        File::put($configPath, $updated);

        try {
            $this->processRunner->testNginxConfig();
            $this->processRunner->reloadNginx();
        } catch (\Throwable $e) {
            File::put($configPath, $original);
            throw $e;
        }

        return ['domain' => $domain, 'redirects' => count($redirects)];
    }

    private function validateRedirect(array $r): array
    {
        $path = (string) ($r['path'] ?? '');
        $destination = (string) ($r['destination'] ?? '');
        $statusCode = (int) ($r['status_code'] ?? 0);

        if ($path === '' || $destination === '' || ! in_array($statusCode, [301, 302], true)) {
            throw new InvalidArgumentException('Redirecionamento inválido.');
        }

        // Path já vem validado no Painel — confere de novo por defesa
        // em profundidade, já que entra direto numa "location" do
        // nginx. Destino também: só http(s) absoluto, pra não permitir
        // injetar outra coisa dentro do "return".
        if (! preg_match('/^\/[a-zA-Z0-9_\-\/]*$/', $path)) {
            throw new InvalidArgumentException("Caminho de redirecionamento inválido: {$path}");
        }

        if (! preg_match('#^https?://[^\s\'"]+$#', $destination)) {
            throw new InvalidArgumentException("Destino de redirecionamento inválido: {$destination}");
        }

        return compact('path', 'destination', 'statusCode');
    }

    /** @param list<array{path: string, destination: string, statusCode: int}> $redirects */
    private function renderBlock(array $redirects): string
    {
        $lines = [];

        foreach ($redirects as $r) {
            $lines[] = "    location {$r['path']} {";
            $lines[] = "        return {$r['statusCode']} {$r['destination']};";
            $lines[] = '    }';
        }

        return implode("\n", $lines);
    }
}
