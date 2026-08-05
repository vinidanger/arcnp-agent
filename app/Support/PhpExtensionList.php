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
     * @return list<array{filename: string, name: string, enabled: bool, type: string}>
     */
    public static function forVersion(string $phpVersion): array
    {
        $iniDir = PhpVersion::config($phpVersion)['ini_dir'];

        $extensions = [];

        foreach (glob("{$iniDir}/*.ini") ?: [] as $path) {
            $filename = basename($path);
            $extensions[] = [
                'filename' => $filename,
                'name' => self::friendlyName($filename),
                'enabled' => true,
                'type' => self::detectType($path),
            ];
        }

        foreach (glob("{$iniDir}/*.ini.disabled") ?: [] as $path) {
            $filename = basename($path, '.disabled');
            $extensions[] = [
                'filename' => $filename,
                'name' => self::friendlyName($filename),
                'enabled' => false,
                'type' => self::detectType($path),
            ];
        }

        usort($extensions, fn ($a, $b) => $a['name'] <=> $b['name']);

        return $extensions;
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
     * "zend" — usado pelo toggle de zend_extension por conta (via flag
     * -d no ExecStart do unit dedicado da conta, ver
     * PhpFpmPoolSettings::renderZendExtensionFlags()), não confundir
     * com availableForPerAccountOptIn() (que é só "extension" normal,
     * via php_admin_value[extension]).
     *
     * @return list<array{filename: string, name: string}>
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
}
