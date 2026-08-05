<?php

namespace App\Services\System;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Único ponto do Agent que invoca comandos privilegiados. Cada método
 * é um comando de forma fixa (sem concatenação de string em shell,
 * args sempre em array) e corresponde 1:1 a uma linha do sudoers
 * (ver deploy/sudoers/arcnp-agent). Nenhuma Action monta comando livre —
 * só chama estes métodos nomeados.
 *
 * Criação/remoção de usuário passa por um script wrapper (scripts/*.sh)
 * em vez de useradd/userdel direto, porque o script também prepara e
 * ajusta a posse de public_html/logs — o Agent (usuário sem privilégio)
 * não teria permissão de escrever dentro do home dir recém-criado
 * (dono é o novo usuário, não o arcnpagent) sem isso.
 */
class ProcessRunner
{
    public function createHostingUser(string $username): void
    {
        $this->exec(['sudo', '-n', base_path('scripts/create-hosting-user.sh'), $username]);
    }

    public function deleteHostingUser(string $username): void
    {
        $this->exec(['sudo', '-n', base_path('scripts/delete-hosting-user.sh'), $username]);
    }

    public function testNginxConfig(): void
    {
        $this->exec(['sudo', '-n', '/usr/sbin/nginx', '-t']);
    }

    public function reloadNginx(): void
    {
        $this->exec(['sudo', '-n', '/usr/bin/systemctl', 'reload', 'nginx']);
    }

    /**
     * Escreve/atualiza o unit systemd dedicado da conta (conteúdo via
     * STDIN) e garante que está rodando com o conteúdo novo — usado
     * tanto pra criar quanto pra trocar de versão de PHP (troca o
     * ExecStart, por isso sempre reinicia, não só recarrega).
     */
    public function applyPhpFpmService(string $unit, string $unitContent): void
    {
        $result = Process::timeout(30)->input($unitContent)->run([
            'sudo', '-n', base_path('scripts/manage-php-fpm-service.sh'), $unit, 'apply',
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Falha ao aplicar serviço PHP-FPM: '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    /**
     * Reescreve o bloco de wrappers de zend_extension no ~/.bashrc da
     * conta (ver manage-cli-zend-profile.sh) — $block vazio remove o
     * bloco inteiro.
     */
    public function updateCliZendProfile(string $username, string $block): void
    {
        $result = Process::timeout(10)->input($block)->run([
            'sudo', '-n', base_path('scripts/manage-cli-zend-profile.sh'), $username,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Falha ao atualizar o perfil de CLI: '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    /**
     * Reload gracioso (SIGUSR2) — só pra troca de tunables do pool, sem
     * mexer no unit nem trocar o binário (diferente de applyPhpFpmService).
     */
    public function reloadPhpFpmService(string $unit): void
    {
        $this->exec(['sudo', '-n', base_path('scripts/manage-php-fpm-service.sh'), $unit, 'reload']);
    }

    public function removePhpFpmService(string $unit): void
    {
        $this->exec(['sudo', '-n', base_path('scripts/manage-php-fpm-service.sh'), $unit, 'remove']);
    }

    public function stopPhpFpmService(string $unit): void
    {
        $this->exec(['sudo', '-n', base_path('scripts/manage-php-fpm-service.sh'), $unit, 'stop']);
    }

    public function startPhpFpmService(string $unit): void
    {
        $this->exec(['sudo', '-n', base_path('scripts/manage-php-fpm-service.sh'), $unit, 'start']);
    }

    /**
     * Consequência de cada conta ter seu próprio processo mestre de
     * PHP-FPM: alternar uma extensão a nível de SERVIDOR
     * (TogglePhpExtensionAction) não tem mais 1 service só pra
     * recarregar — precisa reiniciar todo unit de conta que usa aquele
     * binário/versão. Ver reload-php-fpm-for-binary.sh.
     */
    public function reloadPhpFpmServicesForBinary(string $binary): void
    {
        $this->exec(['sudo', '-n', base_path('scripts/reload-php-fpm-for-binary.sh'), $binary]);
    }

    /**
     * Alterna um .ini de extensão já existente entre habilitado/
     * desabilitado (renomeia pra/de ".ini.disabled") — nunca cria
     * arquivo novo. Reload do PHP-FPM da versão fica por conta de quem
     * chama (TogglePhpExtensionAction), pra não recarregar sem
     * necessidade quando o estado já é o desejado.
     */
    public function togglePhpExtension(string $iniDir, string $filename, bool $enable): void
    {
        $this->exec([
            'sudo', '-n', base_path('scripts/toggle-php-extension.sh'), $iniDir, $filename, $enable ? 'enable' : 'disable',
        ]);
    }

    public function userExists(string $username): bool
    {
        return Process::run(['id', '-u', $username])->successful();
    }

    public function userId(string $username): int
    {
        $result = Process::run(['id', '-u', $username]);

        if ($result->failed()) {
            throw new RuntimeException("Usuário Linux não encontrado: {$username}");
        }

        return (int) trim($result->output());
    }

    public function groupId(string $username): int
    {
        $result = Process::run(['id', '-g', $username]);

        if ($result->failed()) {
            throw new RuntimeException("Usuário Linux não encontrado: {$username}");
        }

        return (int) trim($result->output());
    }

    public function syncMailState(string $bundle): void
    {
        $result = Process::timeout(30)->input($bundle)->run([
            'sudo', '-n', base_path('scripts/manage-mail.sh'), 'sync-state',
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Sincronização de e-mail falhou: '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    /**
     * Idempotente: se a chave já existir pro domínio, só devolve o
     * valor do TXT de novo (não gera outra) — sync_dkim é chamado toda
     * vez que a lista de domínios com DKIM muda, não só na primeira vez.
     */
    public function generateDkimKey(string $domain): string
    {
        $result = Process::timeout(30)->run([
            'sudo', '-n', base_path('scripts/manage-mail-dkim.sh'), 'generate-key', $domain,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Geração de chave DKIM falhou: '.trim($result->errorOutput() ?: $result->output()));
        }

        return trim($result->output());
    }

    public function syncDkimTables(string $bundle): void
    {
        $result = Process::timeout(30)->input($bundle)->run([
            'sudo', '-n', base_path('scripts/manage-mail-dkim.sh'), 'sync-tables',
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Sincronização de tabelas DKIM falhou: '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    public function deleteDkimKey(string $domain): void
    {
        $this->exec(['sudo', '-n', base_path('scripts/manage-mail-dkim.sh'), 'delete-key', $domain]);
    }

    /**
     * $target é o subdiretório (location=inside) ou o domínio inteiro
     * (location=outside, cria domains/{target}/public_html).
     */
    public function createAddonDirectory(string $username, string $location, string $target): void
    {
        $this->exec(['sudo', '-n', base_path('scripts/create-addon-directory.sh'), $username, $location, $target]);
    }

    public function deleteAddonDirectory(string $username, string $location, string $target): void
    {
        $this->exec(['sudo', '-n', base_path('scripts/delete-addon-directory.sh'), $username, $location, $target]);
    }

    /**
     * Certbot/Let's Encrypt envolve chamada de rede externa — timeout
     * bem maior que os outros comandos (e por isso essa Action é
     * assíncrona no Agent, não roda inline na resposta HTTP). Devolve a
     * data de expiração real do certificado (o script lê via
     * `openssl x509 -enddate`, já rodando como root) — null se por
     * algum motivo a linha esperada não vier na saída.
     */
    public function issueSslCertificate(string $domain, string $webroot): ?string
    {
        $result = Process::timeout(120)->run([
            'sudo', '-n', base_path('scripts/issue-ssl-certificate.sh'),
            $domain, $webroot, config('provisioning.ssl_admin_email'),
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Falha ao emitir certificado: '.trim($result->errorOutput() ?: $result->output()));
        }

        if (preg_match('/^EXPIRES_AT=(.+)$/m', $result->output(), $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Renova TODOS os certificados do servidor de uma vez (não é por
     * domínio) — mesmo motivo de timeout longo/assíncrono do
     * issueSslCertificate. Devolve a expiração de CADA certificado que
     * existe no servidor (não só o que foi renovado agora — o script já
     * lê todo mundo, é barato, e simplifica o lado Painel).
     *
     * @return list<array{domain: string, expires_at: string}>
     */
    public function renewAllSslCertificates(): array
    {
        $result = Process::timeout(300)->run(['sudo', '-n', base_path('scripts/renew-ssl-certificates.sh')]);

        if ($result->failed()) {
            throw new RuntimeException('Falha ao renovar certificados: '.trim($result->errorOutput() ?: $result->output()));
        }

        $certs = [];

        foreach (explode("\n", $result->output()) as $line) {
            if (preg_match('/^CERT:([^:]+):(.+)$/', trim($line), $matches)) {
                $certs[] = ['domain' => $matches[1], 'expires_at' => trim($matches[2])];
            }
        }

        return $certs;
    }

    /**
     * Dump sem privilégio (o próprio Agent já tem acesso de leitura ao
     * MySQL via a conexão mysql_admin) — grava comprimido direto no
     * destino. Senha via env MYSQL_PWD em vez de -p na linha de
     * comando, pra não aparecer em `ps`. Buffer inteiro em memória
     * antes de comprimir: aceitável pro tamanho de conta que esse
     * painel atende; se algum dia isso virar gargalo, trocar por um
     * pipe real mysqldump|gzip.
     */
    public function dumpMysqlDatabase(string $dbName, string $destinationPath): void
    {
        $config = config('database.connections.mysql_admin');

        $result = Process::timeout(300)
            ->env(['MYSQL_PWD' => $config['password']])
            ->run([
                'mysqldump',
                '--user='.$config['username'],
                '--host='.($config['host'] ?? '127.0.0.1'),
                '--single-transaction',
                '--quick',
                $dbName,
            ]);

        if ($result->failed()) {
            throw new RuntimeException('mysqldump falhou ('.$dbName.'): '.trim($result->errorOutput()));
        }

        file_put_contents($destinationPath, gzencode($result->output(), 6));
    }

    /**
     * @param  list<string>  $databases
     * @return list<array{filename: string, size: int}>
     */
    public function createBackup(string $username, int $retention, string $tmpDumpDir, array $databases): array
    {
        $result = Process::timeout(600)->run([
            'sudo', '-n', base_path('scripts/create-backup.sh'),
            $username, (string) $retention, $tmpDumpDir,
            ...$databases,
        ]);

        if ($result->failed()) {
            throw new RuntimeException(
                'Backup falhou: '.trim($result->errorOutput() ?: $result->output())
            );
        }

        return json_decode($result->output(), true) ?? [];
    }

    /**
     * @param  list<string>  $filenames
     */
    public function deleteBackupFiles(string $username, array $filenames): void
    {
        $result = Process::timeout(30)->run([
            'sudo', '-n', base_path('scripts/delete-backup.sh'), $username, ...$filenames,
        ]);

        if ($result->failed()) {
            throw new RuntimeException(
                'Falha ao remover backup: '.trim($result->errorOutput() ?: $result->output())
            );
        }
    }

    /**
     * Troca o shell de login entre /bin/bash e /sbin/nologin. Login
     * por senha e por chave convivem (ver set-password) — precisa de
     * PasswordAuthentication yes no sshd_config, ver deploy/README.md.
     */
    public function setSshAccess(string $username, bool $enabled): void
    {
        $this->exec(['sudo', '-n', base_path('scripts/manage-ssh.sh'), $username, 'set-shell', $enabled ? 'enabled' : 'disabled']);
    }

    public function setSshPassword(string $username, string $password): void
    {
        $result = Process::timeout(30)->input($password)->run([
            'sudo', '-n', base_path('scripts/manage-ssh.sh'), $username, 'set-password',
        ]);

        if ($result->failed()) {
            throw new RuntimeException(
                'Troca de senha SSH falhou: '.trim($result->errorOutput() ?: $result->output())
            );
        }
    }

    /**
     * $keysContent já vem pronto (uma chave pública por linha, cada
     * uma já validada pela Action) — regrava ~/.ssh/authorized_keys
     * por inteiro, mesmo padrão idempotente do cron/backup.
     */
    public function syncSshKeys(string $username, string $keysContent): void
    {
        $result = Process::timeout(30)->input($keysContent)->run([
            'sudo', '-n', base_path('scripts/manage-ssh.sh'), $username, 'sync-keys',
        ]);

        if ($result->failed()) {
            throw new RuntimeException(
                'Sincronização de chaves SSH falhou: '.trim($result->errorOutput() ?: $result->output())
            );
        }
    }

    /**
     * $cronLines já vem pronto (uma linha de cron completa por item,
     * já validada campo a campo pela Action) — o script só regrava o
     * arquivo por inteiro e revalida como defesa em profundidade.
     */
    public function syncCronJobs(string $username, string $cronLines): void
    {
        $result = Process::timeout(30)->input($cronLines)->run([
            'sudo', '-n', base_path('scripts/sync-cron.sh'), $username,
        ]);

        if ($result->failed()) {
            throw new RuntimeException(
                'Sincronização de cron falhou: '.trim($result->errorOutput() ?: $result->output())
            );
        }
    }

    public function diskUsageBytes(string $username): int
    {
        $result = Process::timeout(60)->run([
            'sudo', '-n', base_path('scripts/disk-usage.sh'), $username,
        ]);

        if ($result->failed()) {
            throw new RuntimeException(
                'Cálculo de uso de disco falhou: '.trim($result->errorOutput() ?: $result->output())
            );
        }

        return (int) trim($result->output());
    }

    /**
     * Único ponto que muta arquivos dentro da raiz escolhida (criar,
     * salvar, apagar, renomear) — listar/ler não passam por aqui, o
     * Agent já tem ACL de leitura direto (ver FileManagerPath). $root
     * vazio/null = public_html; senão é um domínio com árvore própria
     * fora dela. $content vai pro STDIN do script (nunca argv — arquivo
     * pode ser grande e argv tem limite de tamanho do SO).
     */
    public function manageFile(string $username, string $operation, string $path, ?string $path2 = null, ?string $content = null, ?string $root = null): void
    {
        $command = ['sudo', '-n', base_path('scripts/manage-file.sh'), $username, (string) $root, $operation, $path];

        if ($path2 !== null) {
            $command[] = $path2;
        }

        // 180s, não 30 — esse método também escreve upload de arquivo
        // grande (FileUploadController), e gravar centenas de MB via
        // sudo (com ajuste de posse/ACL) pode legitimamente passar de
        // 30s num disco mais lento.
        $process = Process::timeout(180);

        if ($content !== null) {
            $process = $process->input($content);
        }

        $result = $process->run($command);

        if ($result->failed()) {
            throw new RuntimeException(
                'Operação de arquivo falhou ('.$operation.'): '.trim($result->errorOutput() ?: $result->output())
            );
        }
    }

    /**
     * @param  list<string>  $sourcePaths  Caminhos relativos (à raiz) a incluir no zip.
     */
    public function compressFiles(string $username, array $sourcePaths, string $outputPath, ?string $root = null): void
    {
        // Barra final obrigatória: sem ela, "while read" no bash
        // silenciosamente descarta a ÚLTIMA linha quando ela não termina
        // em quebra de linha (comportamento clássico do bash) — com uma
        // lista de 1 item só, isso perdia o item inteiro.
        $result = Process::timeout(120)->input(implode("\n", $sourcePaths)."\n")->run([
            'sudo', '-n', base_path('scripts/manage-archive.sh'), $username, (string) $root, 'compress', $outputPath,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Falha ao compactar: '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    public function extractArchive(string $username, string $zipPath, string $destPath, ?string $root = null): void
    {
        $result = Process::timeout(120)->run([
            'sudo', '-n', base_path('scripts/manage-archive.sh'), $username, (string) $root, 'extract', $zipPath, $destPath,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Falha ao extrair: '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    /**
     * Timeout bem mais alto que o resto (download + extract + wp-cli
     * podem levar um tempo real) — por isso InstallWordPressAction é
     * assíncrona, essa chamada não bloqueia uma requisição HTTP normal.
     */
    public function installWordPress(
        string $username,
        string $domain,
        ?string $root,
        string $destPath,
        string $downloadUrl,
        string $siteUrl,
        string $dbName,
        string $dbUsername,
        string $dbPassword,
        string $adminUser,
        string $adminPassword,
        string $adminEmail,
        string $siteTitle,
        string $wpConfigContent,
    ): void {
        $result = Process::timeout(300)->input($wpConfigContent)->run([
            'sudo', '-n', base_path('scripts/install-wordpress.sh'),
            $username, $domain, (string) $root, $destPath, $downloadUrl, $siteUrl,
            $dbName, $dbUsername, $dbPassword, $adminUser, $adminPassword, $adminEmail, $siteTitle,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Falha ao instalar WordPress: '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    /**
     * Grava o zone file (validado via named-checkzone dentro do
     * script) — NÃO recarrega. Pra zona nova, o arquivo precisa
     * existir antes do zones.conf referenciar ela; pra edição de
     * registro numa zona já existente, chamar reloadDnsZone() depois.
     */
    public function writeDnsZone(string $domain, string $zoneFileContent): void
    {
        $result = Process::timeout(30)->input($zoneFileContent)->run([
            'sudo', '-n', base_path('scripts/manage-dns-zone.sh'), 'write-zone', $domain,
        ]);

        if ($result->failed()) {
            throw new RuntimeException(
                'Escrita de zona DNS falhou: '.trim($result->errorOutput() ?: $result->output())
            );
        }
    }

    public function reloadDnsZone(string $domain): void
    {
        $this->exec(['sudo', '-n', base_path('scripts/manage-dns-zone.sh'), 'reload-zone', $domain]);
    }

    /**
     * Reescreve /etc/named/zones.conf por inteiro (lista completa de
     * zonas ativas nesse servidor, o Painel é sempre a fonte da
     * verdade) e recarrega o named inteiro — só necessário quando a
     * LISTA de zonas muda (criar/apagar), não pra edição de registro.
     */
    public function syncDnsZonesConf(string $zonesConfContent): void
    {
        $result = Process::timeout(30)->input($zonesConfContent)->run([
            'sudo', '-n', base_path('scripts/manage-dns-zone.sh'), 'sync-zones-conf',
        ]);

        if ($result->failed()) {
            throw new RuntimeException(
                'Sincronização de zones.conf falhou: '.trim($result->errorOutput() ?: $result->output())
            );
        }
    }

    /**
     * Remove só o arquivo da zona — chamar depois de syncDnsZonesConf()
     * já ter tirado ela do named.conf e recarregado (senão o named
     * ainda espera o arquivo existir).
     */
    public function deleteDnsZone(string $domain): void
    {
        $this->exec(['sudo', '-n', base_path('scripts/manage-dns-zone.sh'), 'delete-zone', $domain]);
    }

    /**
     * O vsftpd exige que arquivo dentro de user_config_dir seja dono
     * de root (checagem própria dele) — diferente do banco de usuários
     * virtuais (só lido pelo PAM, sem essa checagem), por isso só essa
     * parte passa por sudo.
     */
    public function syncFtpUserConfigs(string $bundle): void
    {
        $result = Process::timeout(30)->input($bundle)->run([
            'sudo', '-n', base_path('scripts/manage-ftp.sh'), 'sync-user-configs',
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Sincronização de configs de FTP falhou: '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    public function tailNginxLog(string $domain, string $type, int $lines): string
    {
        $result = Process::timeout(30)->run([
            'sudo', '-n', base_path('scripts/tail-log.sh'), 'nginx', $domain, $type, (string) $lines,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Falha ao ler log: '.trim($result->errorOutput() ?: $result->output()));
        }

        return $result->output();
    }

    public function tailMailLog(int $lines, ?string $search): string
    {
        $result = Process::timeout(30)->run([
            'sudo', '-n', base_path('scripts/tail-log.sh'), 'mail', (string) $lines, $search ?? '',
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Falha ao ler log de e-mail: '.trim($result->errorOutput() ?: $result->output()));
        }

        return $result->output();
    }

    /**
     * Escreve+habilita o unit systemd de um app (Node.js/Python) — via
     * sudo porque /etc/systemd/system/ exige root pra escrever e pra
     * chamar daemon-reload/enable, diferente do banco de usuários FTP
     * (só lido pelo PAM, sem exigir dono específico).
     */
    public function createAppUnit(string $unit, string $unitContent): void
    {
        $result = Process::timeout(30)->input($unitContent)->run([
            'sudo', '-n', base_path('scripts/manage-app.sh'), $unit, 'create',
        ]);

        if ($result->failed()) {
            throw new RuntimeException('Falha ao criar serviço do app: '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    public function removeAppUnit(string $unit): void
    {
        $this->exec(['sudo', '-n', base_path('scripts/manage-app.sh'), $unit, 'remove']);
    }

    public function restartAppUnit(string $unit): void
    {
        $this->exec(['sudo', '-n', base_path('scripts/manage-app.sh'), $unit, 'restart']);
    }

    /**
     * Só leitura — consultar o status de um unit systemd não exige
     * privilégio nenhum no systemd (diferente de criar/remover), então
     * roda sem sudo.
     */
    public function appUnitStatus(string $unit): string
    {
        $result = Process::timeout(10)->run(['systemctl', 'is-active', $unit]);

        return trim($result->output()) ?: trim($result->errorOutput()) ?: 'unknown';
    }

    /**
     * Consulta de status de serviço não exige privilégio nenhum no
     * systemd — sem sudo, mesmo motivo de appUnitStatus(). Lista fixa,
     * definida dentro de CollectServerInfoAction (nunca vem do Painel),
     * então não tem superfície de injeção. "systemctl is-active" aceita
     * vários nomes de uma vez e devolve uma linha de status por unit,
     * na mesma ordem — mesmo com alguns inativos (exit code != 0 nesse
     * caso, por isso não usa exec()/->failed(), só lê a saída como veio).
     *
     * @param  list<string>  $units
     * @return array<string, string>
     */
    public function serviceStatuses(array $units): array
    {
        if ($units === []) {
            return [];
        }

        $result = Process::timeout(15)->run(array_merge(['systemctl', 'is-active'], $units));

        $lines = array_pad(explode("\n", trim($result->output())), count($units), 'unknown');

        return array_combine($units, array_map(fn ($line) => trim($line) ?: 'unknown', array_slice($lines, 0, count($units))));
    }

    /**
     * CPU/RAM/processos/I-O da conta, via cgroup nativo do systemd
     * (user-{uid}.slice — ver set-resource-limits.sh). Sem sudo:
     * "systemctl set-property" (escrita) exige privilégio, mas
     * "systemctl show" (leitura de contabilidade) não, mesmo raciocínio
     * de appUnitStatus()/serviceStatuses().
     */
    public function setResourceLimits(string $username, string $cpuPercent, string $memoryMb, string $tasksMax, string $ioWeight): void
    {
        $this->exec([
            'sudo', '-n', base_path('scripts/set-resource-limits.sh'), $username, $cpuPercent, $memoryMb, $tasksMax, $ioWeight,
        ]);
    }

    /**
     * @return array{cpu_usage_ns: ?int, memory_current_bytes: ?int, tasks_current: ?int}
     */
    public function resourceUsage(string $username): array
    {
        $uid = $this->userId($username);

        // Parseado por "Chave=Valor" (sem --value) de propósito — o
        // systemctl show pode OMITIR uma propriedade da saída (ex.:
        // CPUQuota some quando nunca foi setada), o que desalinharia
        // uma leitura posicional tipo "linha 1 = primeira propriedade
        // pedida". Por chave, a ordem/ausência de qualquer uma nunca
        // afeta as outras.
        $result = Process::timeout(10)->run([
            'systemctl', 'show', "user-{$uid}.slice",
            '-p', 'CPUUsageNSec', '-p', 'MemoryCurrent', '-p', 'TasksCurrent',
        ]);

        $values = [];

        foreach (explode("\n", trim($result->output())) as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, null);
            $values[$key] = $value;
        }

        $toInt = fn (?string $value): ?int => $value !== null && ctype_digit($value) ? (int) $value : null;

        return [
            'cpu_usage_ns' => $toInt($values['CPUUsageNSec'] ?? null),
            'memory_current_bytes' => $toInt($values['MemoryCurrent'] ?? null),
            'tasks_current' => $toInt($values['TasksCurrent'] ?? null),
        ];
    }

    /**
     * Cota de disco real (user quota, não XFS project quota — ver
     * set-disk-quota.sh). quotaMb=0 remove a cota (setquota trata 0
     * como "sem limite").
     */
    public function setDiskQuota(string $username, int $quotaMb): void
    {
        $this->exec([
            'sudo', '-n', base_path('scripts/set-disk-quota.sh'),
            $username, (string) $quotaMb, config('provisioning.disk_quota_mount'),
        ]);
    }

    /**
     * Hardware/SO — só leitura, sem sudo, sem nenhum input externo
     * (não recebe parâmetro do Painel), então não tem superfície de
     * injeção. Best-effort peça por peça, igual o heartbeat: uma falha
     * isolada (ex.: /proc/cpuinfo ausente num container) não derruba a
     * coleta inteira, só aquele campo específico fica null/vazio.
     *
     * @return array<string, mixed>
     */
    public function collectSystemInfo(): array
    {
        return [
            'os' => $this->readOsPrettyName(),
            'kernel' => $this->readCommandOutput(['uname', '-r']),
            'architecture' => $this->readCommandOutput(['uname', '-m']),
            'cpu_model' => $this->readCpuModel(),
            'cpu_cores' => $this->readCpuCores(),
            'memory_mb' => $this->readMemoryTotalMb(),
            'uptime_seconds' => $this->readUptimeSeconds(),
            'ip_addresses' => $this->readIpAddresses(),
            'disks' => $this->readDisks(),
        ];
    }

    private function readCommandOutput(array $command): ?string
    {
        $result = Process::timeout(10)->run($command);

        return $result->successful() ? trim($result->output()) ?: null : null;
    }

    private function readOsPrettyName(): ?string
    {
        if (! is_readable('/etc/os-release')) {
            return null;
        }

        foreach (file('/etc/os-release') as $line) {
            if (preg_match('/^PRETTY_NAME="?(.*?)"?$/', trim($line), $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private function readCpuModel(): ?string
    {
        if (! is_readable('/proc/cpuinfo')) {
            return null;
        }

        foreach (file('/proc/cpuinfo') as $line) {
            if (preg_match('/^model name\s*:\s*(.+)$/', trim($line), $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    private function readCpuCores(): ?int
    {
        if (! is_readable('/proc/cpuinfo')) {
            return null;
        }

        $count = 0;

        foreach (file('/proc/cpuinfo') as $line) {
            if (preg_match('/^processor\s*:/', $line)) {
                $count++;
            }
        }

        return $count ?: null;
    }

    private function readMemoryTotalMb(): ?int
    {
        if (! is_readable('/proc/meminfo')) {
            return null;
        }

        foreach (file('/proc/meminfo') as $line) {
            if (preg_match('/^MemTotal:\s+(\d+)/', $line, $m)) {
                return (int) round(((int) $m[1]) / 1024);
            }
        }

        return null;
    }

    private function readUptimeSeconds(): ?int
    {
        if (! is_readable('/proc/uptime')) {
            return null;
        }

        $contents = trim((string) file_get_contents('/proc/uptime'));
        $parts = explode(' ', $contents);

        return isset($parts[0]) ? (int) round((float) $parts[0]) : null;
    }

    /** @return list<string> */
    private function readIpAddresses(): array
    {
        $output = $this->readCommandOutput(['hostname', '-I']);

        return $output ? array_values(array_filter(explode(' ', $output))) : [];
    }

    /**
     * @return list<array{filesystem: string, mount: string, size: string, used: string, available: string, percent: string}>
     */
    private function readDisks(): array
    {
        $result = Process::timeout(10)->run(['df', '-hP']);

        if (! $result->successful()) {
            return [];
        }

        $disks = [];

        foreach (array_slice(explode("\n", trim($result->output())), 1) as $line) {
            $columns = preg_split('/\s+/', trim($line));

            if (count($columns) < 6 || ! str_starts_with($columns[0], '/dev/')) {
                continue;
            }

            $disks[] = [
                'filesystem' => $columns[0],
                'size' => $columns[1],
                'used' => $columns[2],
                'available' => $columns[3],
                'percent' => $columns[4],
                'mount' => $columns[5],
            ];
        }

        return $disks;
    }

    private function exec(array $command, int $timeout = 30): void
    {
        $result = Process::timeout($timeout)->run($command);

        if ($result->failed()) {
            throw new RuntimeException(
                'Comando falhou ('.implode(' ', $command).'): '.trim($result->errorOutput() ?: $result->output())
            );
        }
    }
}
