<?php

namespace App\Actions\Installer;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Support\DomainName;
use App\Support\LinuxUsername;
use App\Support\Subdirectory;
use App\Support\WpConfigTemplate;
use InvalidArgumentException;

/**
 * Assíncrona de propósito — download + extração + wp-cli podem levar
 * bem mais que uma requisição HTTP normal (mesmo raciocínio de
 * IssueSslCertificateAction/CreateBackupAction).
 */
class InstallWordPressAction implements AgentAction
{
    public function __construct(private ProcessRunner $processRunner)
    {
    }

    public function isAsync(): bool
    {
        return true;
    }

    public function execute(array $payload): array
    {
        $username = LinuxUsername::validate($payload['username'] ?? '');
        $domain = DomainName::validate($payload['domain'] ?? '');
        $location = ($payload['location'] ?? null) === 'outside' ? 'outside' : null;
        $subdir = blank($payload['subdir'] ?? null) ? null : Subdirectory::validate($payload['subdir']);
        $downloadUrl = (string) ($payload['download_url'] ?? '');
        $dbName = (string) ($payload['db_name'] ?? '');
        $dbUsername = (string) ($payload['db_username'] ?? '');
        $dbPassword = (string) ($payload['db_password'] ?? '');
        $adminUser = (string) ($payload['admin_user'] ?? '');
        $adminPassword = (string) ($payload['admin_password'] ?? '');
        $adminEmail = (string) ($payload['admin_email'] ?? '');
        $siteTitle = (string) ($payload['site_title'] ?? '');

        if ($dbName === '' || $dbUsername === '' || $dbPassword === '') {
            throw new InvalidArgumentException('Credenciais de banco de dados são obrigatórias.');
        }

        if ($adminUser === '' || $adminPassword === '' || $adminEmail === '') {
            throw new InvalidArgumentException('Dados do usuário admin são obrigatórios.');
        }

        // Defesa em profundidade — o Painel só manda a URL fixa do
        // catálogo (config/app_catalog.php), mas revalida aqui mesmo
        // assim (nunca confiar só na validação de quem chamou, mesmo
        // padrão do resto do Agent). O script sudo também revalida.
        $host = parse_url($downloadUrl, PHP_URL_HOST) ?: '';
        $scheme = parse_url($downloadUrl, PHP_URL_SCHEME) ?: '';

        if ($scheme !== 'https' || ! preg_match('/(^|\.)wordpress\.org$/', $host)) {
            throw new InvalidArgumentException("URL de download não permitida: {$downloadUrl}");
        }

        $root = $location === 'outside' ? $domain : null;
        $destPath = $subdir ?? '';

        $wpConfigContent = WpConfigTemplate::render($dbName, $dbUsername, $dbPassword);

        $this->processRunner->installWordPress(
            username: $username,
            domain: $domain,
            root: $root,
            destPath: $destPath,
            downloadUrl: $downloadUrl,
            dbName: $dbName,
            dbUsername: $dbUsername,
            dbPassword: $dbPassword,
            adminUser: $adminUser,
            adminPassword: $adminPassword,
            adminEmail: $adminEmail,
            siteTitle: $siteTitle !== '' ? $siteTitle : $domain,
            wpConfigContent: $wpConfigContent,
        );

        return [
            'admin_url' => "http://{$domain}/wp-admin/",
            'site_url' => "http://{$domain}/",
        ];
    }
}
