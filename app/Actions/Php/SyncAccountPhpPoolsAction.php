<?php

namespace App\Actions\Php;

use App\Actions\Contracts\AgentAction;
use App\Services\System\ProcessRunner;
use App\Services\System\TemplateRenderer;
use App\Support\DomainName;
use App\Support\LinuxUsername;
use App\Support\PhpFpmPool;
use App\Support\PhpFpmPoolSettings;
use App\Support\PhpVersion;
use Illuminate\Support\Facades\File;

/**
 * Resync COMPLETO dos processos PHP-FPM de uma conta — recebe sempre o
 * estado inteiro (todo domínio da conta, principal incluso, cada um
 * com sua própria versão/settings), mesmo padrão "Painel é sempre
 * fonte da verdade" já usado pra e-mail/FTP/cron/DNS neste projeto.
 * Substitui os antigos php.create_pool/delete_pool/switch_version/
 * update_pool_settings/update_zend_extensions — um resync total
 * elimina os casos de borda de tentar aplicar cada mudança
 * incrementalmente (criar domínio, trocar versão de um só, remover
 * domínio, etc. viram todos só "recalcula o estado desejado e
 * sincroniza").
 *
 * Agrupa domínios por "grupo" (versão + assinatura das zend_extensions
 * — ver PhpFpmPool::processGroupKey(), não é só por versão: dois
 * domínios na mesma versão mas com zend_extensions diferentes NÃO
 * podem compartilhar processo, porque PHP_INI_SCAN_DIR é uma variável
 * de ambiente do PROCESSO inteiro, não escopável por pool). Domínios
 * SEM lista alguma são pulados (payload vazio = desprovisiona tudo —
 * usado no rollback/exclusão de conta).
 *
 * Ao final, também atualiza o ~/.bashrc da conta (ver
 * buildCliZendProfileBlock()/ProcessRunner::updateCliZendProfile())
 * pra que zend_extension ativa em algum domínio valha também pro PHP
 * CLI via SSH da conta — sem isso, `php artisan` (ou qualquer script
 * dependente de ioncube) rodado via SSH usaria o php.ini padrão do
 * sistema, sem a extensão, mesmo com o site funcionando certo.
 */
class SyncAccountPhpPoolsAction implements AgentAction
{
    public function __construct(
        private ProcessRunner $processRunner,
        private TemplateRenderer $templateRenderer,
    ) {
    }

    public function isAsync(): bool
    {
        return false;
    }

    public function execute(array $payload): array
    {
        $username = LinuxUsername::validate($payload['username'] ?? '');
        $domainPayloads = $payload['domains'] ?? [];

        $groups = [];

        foreach ($domainPayloads as $item) {
            $domain = DomainName::validate($item['domain'] ?? '');
            $phpVersion = (string) ($item['php_version'] ?? '');
            PhpVersion::config($phpVersion);
            $settings = is_array($item['settings'] ?? null) ? $item['settings'] : [];

            $zendCsv = PhpFpmPoolSettings::sanitizeZendExtensions($phpVersion, (string) ($settings['zend_extensions'] ?? ''));
            $groupKey = PhpFpmPool::processGroupKey($phpVersion, $zendCsv);

            $groups[$groupKey]['php_version'] = $phpVersion;
            $groups[$groupKey]['zend_csv'] = $zendCsv;
            $groups[$groupKey]['domains'][] = ['domain' => $domain, 'settings' => $settings];
        }

        $syncedGroupKeys = [];
        // Zend dirs agrupados por VERSÃO (não por grupo) — usado só pra
        // montar o wrapper de CLI (updateCliZendProfile()) mais abaixo,
        // que é por versão/comando (php81, php82...), não por grupo.
        $versionZendDirs = [];

        foreach ($groups as $groupKey => $group) {
            // PHP casta chave de array string numérica ("83") pra int
            // automaticamente — sem o (string) aqui, o "in_array(...,
            // true)" (comparação estrita) de removeOrphanedGroups()
            // nunca bateria contra as chaves sempre-string vindas de
            // allGroupKeysForUsername(), e o grupo recém-criado seria
            // apagado como "órfão" na mesma execução.
            $groupKey = (string) $groupKey;
            $zendDir = $this->applyGroup($username, $groupKey, $group['php_version'], $group['zend_csv'], $group['domains']);
            $syncedGroupKeys[] = $groupKey;

            if ($zendDir !== '') {
                $versionZendDirs[$group['php_version']][] = $zendDir;
            }
        }

        $this->removeOrphanedGroups($username, $syncedGroupKeys);
        $this->processRunner->updateCliZendProfile($username, $this->buildCliZendProfileBlock($versionZendDirs));

        return ['username' => $username, 'groups' => $syncedGroupKeys];
    }

    /**
     * @param  list<array{domain: string, settings: array}>  $domains
     * @return string caminho do diretório de zend do grupo, ou '' se nenhum
     */
    private function applyGroup(string $username, string $groupKey, string $phpVersion, string $zendCsv, array $domains): string
    {
        $zendIniLines = PhpFpmPoolSettings::buildZendIniLines($phpVersion, $zendCsv);
        $zendDir = PhpFpmPool::applyZendIni($username, $groupKey, $zendIniLines);

        $globalVariables = PhpFpmPoolSettings::globalVariables($username, $groupKey, $phpVersion);

        $confContent = $this->templateRenderer->render('php-fpm-master-global', $globalVariables)."\n";

        foreach ($domains as $item) {
            $poolVariables = PhpFpmPoolSettings::variables($username, $item['domain'], $phpVersion, $item['settings']);
            $confContent .= $this->templateRenderer->render('php-fpm-domain-pool', $poolVariables)."\n";
        }

        File::put(PhpFpmPool::configPath($username, $groupKey), $confContent);

        $globalVariables['uid'] = $this->processRunner->userId($username);
        $globalVariables['zend_ini_scan_dir_line'] = $zendDir === ''
            ? ''
            : 'Environment=PHP_INI_SCAN_DIR='.$zendDir.':'.PhpVersion::config($phpVersion)['ini_dir'];

        $serviceContent = $this->templateRenderer->render('php-fpm-master.service', $globalVariables);

        $this->processRunner->applyPhpFpmService(PhpFpmPool::serviceName($username, $groupKey), $serviceContent);

        return $zendDir;
    }

    /**
     * Monta o bloco de wrappers de shell (um por versão de PHP com
     * zend_extension ativa em algum domínio da conta) que ativam o
     * PHP_INI_SCAN_DIR também no CLI via SSH — mesmo raciocínio do FPM
     * (ver applyGroup()), só que aqui é por VERSÃO (o CLI não tem
     * conceito de "domínio"), então usa a UNIÃO de todos os diretórios
     * de zend daquela versão entre os domínios da conta. "command
     * {$cli}" (não o caminho direto do binário) evita depender de
     * saber o caminho exato — deixa o PATH da conta resolver, e evita
     * a função recursar nela mesma.
     *
     * @param  array<string, list<string>>  $versionZendDirs
     */
    private function buildCliZendProfileBlock(array $versionZendDirs): string
    {
        $lines = [];

        foreach ($versionZendDirs as $phpVersion => $dirs) {
            $config = PhpVersion::config($phpVersion);
            $cli = $config['cli_command'] ?? null;

            if ($cli === null || empty($dirs)) {
                continue;
            }

            $scanDir = implode(':', array_unique($dirs)).':'.$config['ini_dir'];
            $lines[] = "{$cli}() { PHP_INI_SCAN_DIR=\"{$scanDir}\" command {$cli} \"\$@\"; }";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $currentGroupKeys
     */
    private function removeOrphanedGroups(string $username, array $currentGroupKeys): void
    {
        foreach (PhpFpmPool::allGroupKeysForUsername($username) as $groupKey) {
            if (in_array($groupKey, $currentGroupKeys, true)) {
                continue;
            }

            $this->processRunner->removePhpFpmService(PhpFpmPool::serviceName($username, $groupKey));

            $configPath = PhpFpmPool::configPath($username, $groupKey);

            if (File::exists($configPath)) {
                File::delete($configPath);
            }

            PhpFpmPool::applyZendIni($username, $groupKey, '');
        }
    }
}
