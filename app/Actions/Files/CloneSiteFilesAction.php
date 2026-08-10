<?php

namespace App\Actions\Files;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\DocumentRoot;
use App\Support\DomainName;
use App\Support\LinuxUsername;
use App\Support\PublicPath;
use App\Support\Subdirectory;

/**
 * Clone de staging — copia os arquivos do domínio de ORIGEM (produção)
 * pro domínio de DESTINO (staging), que precisa já ter sido criado
 * vazio antes (via web.create_addon_domain, sempre location=outside —
 * decisão de design: clone sempre ganha árvore própria em
 * domains/{destino}/public_html, nunca fica aninhado dentro do
 * public_html de produção).
 */
class CloneSiteFilesAction implements AgentAction
{
    public function __construct(private ProcessRunner $processRunner)
    {
    }

    public function isAsync(): bool
    {
        return false;
    }

    public function execute(array $payload): array
    {
        $username = LinuxUsername::validate($payload['username'] ?? '');
        $sourceDomain = DomainName::validate($payload['source_domain'] ?? '');
        $sourceLocation = ($payload['source_location'] ?? null) === 'outside' ? 'outside' : null;
        $sourceSubdir = blank($payload['source_subdir'] ?? null) ? null : Subdirectory::validate($payload['source_subdir']);
        $sourcePublicPath = blank($payload['source_public_path'] ?? null) ? null : PublicPath::validate($payload['source_public_path']);
        $destDomain = DomainName::validate($payload['dest_domain'] ?? '');

        $homeDir = config('provisioning.home_base_dir')."/{$username}";

        $sourceAbsolute = DocumentRoot::resolve($homeDir, $sourceDomain, $sourceLocation, $sourceSubdir, $sourcePublicPath);
        $destAbsolute = DocumentRoot::resolve($homeDir, $destDomain, 'outside', null, null);

        $sourceRelative = ltrim(substr($sourceAbsolute, strlen($homeDir)), '/');
        $destRelative = ltrim(substr($destAbsolute, strlen($homeDir)), '/');

        $this->processRunner->cloneSiteFiles($username, $sourceRelative, $destRelative);

        return ['source' => $sourceAbsolute, 'destination' => $destAbsolute];
    }
}
