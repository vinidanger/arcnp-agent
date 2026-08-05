<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * PHP por DOMÍNIO, não mais por conta inteira: cada domínio (principal
 * ou adicional) pode ter versão/extensões/tunáveis próprios.
 *
 * Identidade do PROCESSO MESTRE = "grupo" (conta + versão + assinatura
 * das zend_extensions, ver processGroupKey()) — NÃO é só (conta,
 * versão). Motivo: pools dentro de um mesmo processo mestre são
 * obrigados a compartilhar o mesmo binário (óbvio: 1 processo, 1
 * versão) E o mesmo `PHP_INI_SCAN_DIR` (variável de ambiente do
 * PROCESSO inteiro, lida pelo motor do PHP no boot — não existe
 * mecanismo de FPM pra escopar isso por pool; php_admin_value/env[] de
 * pool só valem em tempo de request, tarde demais pra afetar
 * carregamento de zend_extension). Ou seja: dois domínios na MESMA
 * versão mas com zend_extensions DIFERENTES não podem dividir o mesmo
 * processo — só domínios com versão E conjunto de zend_extensions
 * IDÊNTICOS caem no mesmo grupo/processo. Domínios sem nenhuma
 * zend_extension (o caso comum) continuam todos agrupados só por
 * versão, sem processo extra.
 *
 * Identidade do POOL (bloco dentro do .conf) = por DOMÍNIO — é isso
 * que permite memory_limit/extra_extensions diferentes por domínio
 * mesmo dentro do mesmo grupo/processo.
 *
 * O socket só depende de (conta, domínio) — nunca do grupo/versão —
 * então trocar a versão (ou as zend_extensions) de um domínio nunca
 * precisa reescrever o vhost nginx dele, só ressincronizar os
 * processos PHP-FPM (ver SyncAccountPhpPoolsAction).
 */
class PhpFpmPool
{
    public static function socketPath(string $username, string $domain): string
    {
        return "/run/arcnp-php/{$username}--{$domain}.sock";
    }

    public static function configPath(string $username, string $groupKey): string
    {
        return config('provisioning.php_fpm_config_dir')."/{$username}-{$groupKey}.conf";
    }

    public static function serviceName(string $username, string $groupKey): string
    {
        return "arcnp-php-{$username}-{$groupKey}.service";
    }

    /**
     * Chave do grupo/processo: versão sem pontos + sufixo hash curto das
     * zend_extensions (ausente quando o domínio não tem nenhuma — é o
     * caso comum, mantém o nome igual ao esquema anterior pra esses
     * casos). $zendExtensionsCsv precisa já vir SANITIZADO (ver
     * PhpFpmPoolSettings::sanitizeZendExtensions()) — quem chama isso é
     * sempre SyncAccountPhpPoolsAction, depois de sanitizar.
     */
    public static function processGroupKey(string $phpVersion, string $zendExtensionsCsv): string
    {
        $versionSlug = str_replace('.', '', $phpVersion);

        if ($zendExtensionsCsv === '') {
            return $versionSlug;
        }

        return $versionSlug.'-z'.substr(md5($zendExtensionsCsv), 0, 8);
    }

    /**
     * Descobre as chaves de grupo de todos os processos PHP-FPM já
     * existentes de uma conta (um por grupo versão+zend_extensions em
     * uso entre os domínios dela) — usado por
     * SuspendHostingAccountAction/ReactivateHostingAccountAction (que
     * precisam parar/religar TODOS, não só um) e por
     * SyncAccountPhpPoolsAction (pra descobrir quais grupos sobraram
     * sem nenhum domínio e precisam ser removidos). Username nunca
     * contém "-" (charset validado por LinuxUsername::validate()),
     * então o primeiro "-" depois dele sempre marca o início da chave
     * de grupo, sem ambiguidade com outro username que comece com o
     * mesmo prefixo.
     *
     * @return list<string> chaves de grupo, ex. "83" ou "81-za1b2c3d4"
     */
    public static function allGroupKeysForUsername(string $username): array
    {
        $dir = config('provisioning.php_fpm_config_dir');
        $matches = glob("{$dir}/{$username}-*.conf") ?: [];
        $prefix = "{$username}-";

        return array_map(
            fn (string $path) => preg_replace('/\.conf$/', '', substr(basename($path), strlen($prefix))),
            $matches
        );
    }

    /**
     * Diretório de scan de ini PRÓPRIO do grupo, usado só quando os
     * domínios dele têm zend_extension habilitada (ver
     * PhpFpmPoolSettings) — compartilhado por todos os domínios do
     * grupo, já que por definição todos eles têm o MESMO conjunto de
     * zend_extensions (é exatamente isso que os agrupa). Fica dentro do
     * mesmo diretório que o Agent já escreve sem sudo
     * (php_fpm_config_dir), então criar/remover isso também não precisa
     * de privilégio novo.
     */
    public static function zendIniDir(string $username, string $groupKey): string
    {
        return config('provisioning.php_fpm_config_dir')."/{$username}-{$groupKey}-zend.d";
    }

    /**
     * Escreve (ou remove, se $lines vier vazio) o ini de zend_extension
     * desse grupo dentro do diretório próprio dele. Devolve o caminho
     * do diretório quando escreveu algo, ou string vazia quando não há
     * zend_extension nesse grupo (uso: montar o Environment=
     * PHP_INI_SCAN_DIR do processo mestre).
     *
     * Importante: o nome do arquivo ("00-zend.ini") precisa ordenar
     * ANTES de qualquer coisa no diretório PADRÃO da versão — é isso
     * que garante que um zend_extension exigente sobre ordem (caso do
     * ioncube_loader, que recusa carregar se não for "a primeira
     * entrada") funcione mesmo quando o grupo tem várias linhas aqui.
     */
    public static function applyZendIni(string $username, string $groupKey, string $lines): string
    {
        $dir = self::zendIniDir($username, $groupKey);

        if ($lines === '') {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }

            return '';
        }

        File::ensureDirectoryExists($dir);
        File::put("{$dir}/00-zend.ini", $lines."\n");

        return $dir;
    }
}
