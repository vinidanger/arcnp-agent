<?php

namespace App\Support;

class PhpExtensionList
{
    /**
     * Lê o diretório de ini da versão via glob puro (sem shell, sem
     * sudo — diretório é legível por qualquer usuário local, mesmo
     * padrão de leitura sem privilégio usado em outras partes do
     * Agent). Nome "amigável" é o nome do arquivo sem o prefixo
     * numérico (padrão Remi/RPM, ex. "20-redis.ini" -> "redis") e sem
     * sufixo .ini/.ini.disabled — convenção de exibição só, o arquivo
     * real (campo "filename") é o que qualquer operação usa de verdade.
     *
     * "type" distingue "extension" (carregável por pool, via
     * php_admin_value[extension] — ver PhpFpmPoolSettings) de "zend"
     * (zend_extension, ex. ioncube_loader/opcache/xdebug — só pode
     * valer pro processo mestre inteiro, nunca por pool/conta, ver
     * detectType()).
     *
     * "zend_directive": só preenchido pra type==='zend' — é o valor
     * REAL da linha "zend_extension=..." do arquivo (ex.
     * "ioncube_loader_lin_8.3.so"), não o "name" amigável (que pode não
     * bater com o nome do .so de verdade — caso do ioncube, cujo
     * arquivo tem sufixo de versão). Usado por
     * PhpFpmPoolSettings::buildZendIniLines() pra montar a diretiva
     * certa no ini por conta, em vez de assumir "{name}.so".
     *
     * @return list<array{filename: string, name: string, enabled: bool, type: string, zend_directive: ?string}>
     */
    public static function forVersion(string $phpVersion): array
    {
        $iniDir = PhpVersion::config($phpVersion)['ini_dir'];

        $extensions = [];

        foreach (glob("{$iniDir}/*.ini") ?: [] as $path) {
            $filename = basename($path);
            $extensions[] = self::describe($filename, $path, true);
        }

        foreach (glob("{$iniDir}/*.ini.disabled") ?: [] as $path) {
            $filename = basename($path, '.disabled');
            $extensions[] = self::describe($filename, $path, false);
        }

        usort($extensions, fn ($a, $b) => $a['name'] <=> $b['name']);

        return $extensions;
    }

    private static function describe(string $filename, string $path, bool $enabled): array
    {
        $type = self::detectType($path);

        return [
            'filename' => $filename,
            'name' => self::friendlyName($filename),
            'enabled' => $enabled,
            'type' => $type,
            'zend_directive' => $type === 'zend' ? self::zendDirectiveValue($path) : null,
        ];
    }

    /**
     * Extensões que estão ATIVAS no servidor inteiro (todas as contas
     * já têm acesso) — não faz sentido oferecer isso como "opt-in por
     * conta" de novo. Só "extension" (não-zend) E atualmente desativada
     * a nível de servidor entra na lista — é exatamente o conjunto que
     * uma conta pode pedir pro próprio pool sem duplicar carregamento
     * nem mexer em nada que não seja dela.
     *
     * @return list<array{filename: string, name: string}>
     */
    public static function availableForPerAccountOptIn(string $phpVersion): array
    {
        return array_values(array_filter(
            self::forVersion($phpVersion),
            fn (array $ext) => $ext['type'] === 'extension' && ! $ext['enabled']
        ));
    }

    /**
     * Mesma regra de availableForPerAccountOptIn(), só que pro lado
     * "zend" — usado pelo toggle de zend_extension por conta (via um
     * php.ini próprio da conta, scaneado antes do diretório padrão, ver
     * PhpFpmPoolSettings::buildZendIniLines()), não confundir com
     * availableForPerAccountOptIn() (que é só "extension" normal, via
     * php_admin_value[extension]).
     *
     * @return list<array{filename: string, name: string, zend_directive: ?string}>
     */
    public static function availableZendExtensionsForPerAccountOptIn(string $phpVersion): array
    {
        return array_values(array_filter(
            self::forVersion($phpVersion),
            fn (array $ext) => $ext['type'] === 'zend' && ! $ext['enabled']
        ));
    }

    private static function friendlyName(string $filename): string
    {
        $name = preg_replace('/\.ini$/', '', $filename);

        return preg_replace('/^\d+-/', '', $name);
    }

    private static function detectType(string $path): string
    {
        $contents = @file_get_contents($path) ?: '';

        return str_contains($contents, 'zend_extension') ? 'zend' : 'extension';
    }

    /**
     * Valor literal depois do "=" da linha "zend_extension" — não dá
     * pra assumir "{name}.so" (caso do ioncube, cujo arquivo real tem
     * sufixo de versão, ex. "ioncube_loader_lin_8.3.so", diferente do
     * "name" amigável "ioncube"). null se o arquivo não tiver a
     * diretiva (não deveria acontecer pra um arquivo já classificado
     * como "zend" por detectType(), mas evita warning se acontecer).
     */
    private static function zendDirectiveValue(string $path): ?string
    {
        $contents = @file_get_contents($path) ?: '';

        if (preg_match('/^\s*zend_extension\s*=\s*(.+?)\s*$/mi', $contents, $matches) === 1) {
            return trim($matches[1], "\"' \t");
        }

        return null;
    }
}
