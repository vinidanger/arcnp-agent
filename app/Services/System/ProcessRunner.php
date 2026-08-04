<?php

namespace App\Services\System;

use App\Support\PhpVersion;
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
     * Recarrega o php-fpm.service dessa versão específica — cada versão
     * é isolada num serviço próprio (ver config/provisioning.php), então
     * reload de um pool nunca afeta contas de outra versão nem o
     * Painel/Agent.
     */
    public function reloadPhpFpm(string $phpVersion): void
    {
        $this->exec(['sudo', '-n', '/usr/bin/systemctl', 'reload', PhpVersion::config($phpVersion)['service']]);
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
     * assíncrona no Agent, não roda inline na resposta HTTP).
     */
    public function issueSslCertificate(string $domain, string $webroot): void
    {
        $this->exec([
            'sudo', '-n', base_path('scripts/issue-ssl-certificate.sh'),
            $domain, $webroot, config('provisioning.ssl_admin_email'),
        ], 120);
    }

    /**
     * Renova TODOS os certificados do servidor de uma vez (não é por
     * domínio) — mesmo motivo de timeout longo/assíncrono do issueSslCertificate.
     */
    public function renewAllSslCertificates(): void
    {
        $this->exec(['sudo', '-n', base_path('scripts/renew-ssl-certificates.sh')], 300);
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
            $username, $domain, (string) $root, $destPath, $downloadUrl,
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
