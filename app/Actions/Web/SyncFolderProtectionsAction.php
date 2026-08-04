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
 * Reescreve só o bloco entre os marcadores ARCNP:PROTECTED-LOCATIONS
 * dentro do vhost já existente — não regenera o arquivo inteiro (isso
 * é feito por CreateVirtualHostAction/UpdateVirtualHostPhpVersionAction/
 * IssueSslCertificateAction a partir do stub, que não sabe nada sobre
 * proteção de pasta). Se os marcadores ainda não existirem (vhost
 * criado ou regenerado do zero por uma dessas outras Actions), insere
 * um bloco novo antes do último "}" do arquivo — auto-cura, não
 * depende de migrar os stubs. O Painel reenvia o estado completo das
 * proteções desse domínio a cada mutação, mesmo padrão do mail/DNS.
 */
class SyncFolderProtectionsAction implements AgentAction
{
    private const MARKER_BEGIN = '# ARCNP:PROTECTED-LOCATIONS:BEGIN';

    private const MARKER_END = '# ARCNP:PROTECTED-LOCATIONS:END';

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

        $protections = array_map(fn (array $p) => $this->validateProtection($p), $payload['protections'] ?? []);

        if ($protections === [] && ! $hasMarkers) {
            return ['domain' => $domain, 'protections' => 0];
        }

        $htpasswdDir = config('provisioning.htpasswd_dir')."/{$domain}";
        File::ensureDirectoryExists($htpasswdDir, 0755, true);
        $this->syncHtpasswdFiles($htpasswdDir, $protections);

        $updated = VhostExtraBlock::replace($original, self::MARKER_BEGIN, self::MARKER_END, $this->renderBlock($protections, $htpasswdDir));

        File::put($configPath, $updated);

        try {
            $this->processRunner->testNginxConfig();
            $this->processRunner->reloadNginx();
        } catch (\Throwable $e) {
            File::put($configPath, $original);
            throw $e;
        }

        return ['domain' => $domain, 'protections' => count($protections)];
    }

    private function validateProtection(array $p): array
    {
        $id = (int) ($p['id'] ?? 0);
        $path = (string) ($p['path'] ?? '');
        $username = (string) ($p['htpasswd_username'] ?? '');
        $passwordHash = (string) ($p['password_hash'] ?? '');

        if ($id <= 0 || $path === '' || $username === '' || $passwordHash === '') {
            throw new InvalidArgumentException('Proteção de pasta inválida.');
        }

        // O caminho já vem validado no Painel (regex restrita) — confere
        // de novo aqui por defesa em profundidade, já que ele entra
        // direto numa diretiva "location" do nginx.
        if (! preg_match('/^\/[a-zA-Z0-9_\-\/]*$/', $path)) {
            throw new InvalidArgumentException("Caminho de proteção inválido: {$path}");
        }

        return compact('id', 'path', 'username', 'passwordHash');
    }

    /** @param list<array{id: int, path: string, username: string, passwordHash: string}> $protections */
    private function syncHtpasswdFiles(string $htpasswdDir, array $protections): void
    {
        $keep = [];

        foreach ($protections as $p) {
            $file = "{$htpasswdDir}/{$p['id']}.htpasswd";
            File::put($file, "{$p['username']}:{$p['passwordHash']}\n");
            chmod($file, 0644);
            $keep[] = $file;
        }

        foreach (File::glob("{$htpasswdDir}/*.htpasswd") as $existing) {
            if (! in_array($existing, $keep, true)) {
                File::delete($existing);
            }
        }
    }

    /** @param list<array{id: int, path: string, username: string, passwordHash: string}> $protections */
    private function renderBlock(array $protections, string $htpasswdDir): string
    {
        $lines = [];

        foreach ($protections as $p) {
            $lines[] = "    location {$p['path']} {";
            $lines[] = '        auth_basic "Área restrita";';
            $lines[] = "        auth_basic_user_file {$htpasswdDir}/{$p['id']}.htpasswd;";
            $lines[] = '    }';
        }

        return implode("\n", $lines);
    }
}
