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
 * Mesmo mecanismo de bloco-entre-marcadores da proteção de pasta/
 * redirecionamentos (ver VhostExtraBlock). Descartei a ideia original
 * de um bloco "types{}" custom: um "types{}" num contexto mais
 * específico (server) SUBSTITUI por completo o herdado do http{} (não
 * é cumulativo), e tentar "restaurar" a tabela padrão com
 * "include mime.types;" DENTRO desse bloco quebra de outro jeito — o
 * arquivo /etc/nginx/mime.types já é ele mesmo um bloco "types { ... }"
 * inteiro, então incluí-lo dentro do nosso types{} aninha um types{}
 * dentro de outro, que o nginx rejeita (erro real visto em produção:
 * "unexpected '{' in mime.types:2"). A correção é não mexer na tabela
 * de tipos nenhuma — cada regra vira um "location" só pra extensão
 * daquele arquivo, com "default_type", que nunca precisa da tabela
 * padrão pra funcionar e nunca conflita com o resto do vhost.
 */
class SyncMimeTypesAction implements AgentAction
{
    private const MARKER_BEGIN = '# ARCNP:MIME-TYPES:BEGIN';

    private const MARKER_END = '# ARCNP:MIME-TYPES:END';

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

        $rules = array_map(fn (array $r) => $this->validateRule($r), $payload['rules'] ?? []);

        if ($rules === [] && ! $hasMarkers) {
            return ['domain' => $domain, 'rules' => 0];
        }

        $updated = VhostExtraBlock::replace($original, self::MARKER_BEGIN, self::MARKER_END, $this->renderBlock($rules));

        File::put($configPath, $updated);

        try {
            $this->processRunner->testNginxConfig();
            $this->processRunner->reloadNginx();
        } catch (\Throwable $e) {
            File::put($configPath, $original);
            throw $e;
        }

        return ['domain' => $domain, 'rules' => count($rules)];
    }

    private function validateRule(array $r): array
    {
        $extension = (string) ($r['extension'] ?? '');
        $mimeType = (string) ($r['mime_type'] ?? '');

        // Path já vem validado no Painel — confere de novo por defesa
        // em profundidade, já que entra direto num "location" regex do
        // nginx. Extensão: só letras/números/ponto (pra permitir algo
        // tipo "tar.gz" — o ponto em si é escapado antes de virar
        // regex, ver renderBlock()). Tipo MIME: formato padrão
        // "categoria/subtipo", com os caracteres que RFC 6838 permite
        // num subtipo.
        if (! preg_match('/^[a-zA-Z0-9.]+$/', $extension)) {
            throw new InvalidArgumentException("Extensão inválida: {$extension}");
        }

        if (! preg_match('~^[a-zA-Z0-9][a-zA-Z0-9!#$&.+\-^_]*/[a-zA-Z0-9][a-zA-Z0-9!#$&.+\-^_]*$~', $mimeType)) {
            throw new InvalidArgumentException("Tipo MIME inválido: {$mimeType}");
        }

        return compact('extension', 'mimeType');
    }

    /** @param list<array{extension: string, mimeType: string}> $rules */
    private function renderBlock(array $rules): string
    {
        if ($rules === []) {
            return '';
        }

        $lines = [];

        foreach ($rules as $r) {
            // Ponto literal escapado ("tar.gz" -> "tar\.gz") — sem isso
            // o "." do regex do nginx bateria com QUALQUER caractere
            // ali, não só um ponto de verdade.
            $escapedExtension = str_replace('.', '\.', $r['extension']);

            $lines[] = "    location ~* \\.{$escapedExtension}$ {";
            $lines[] = "        default_type {$r['mimeType']};";
            $lines[] = '    }';
        }

        return implode("\n", $lines);
    }
}
