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
     * @return list<array{filename: string, name: string, enabled: bool}>
     */
    public static function forVersion(string $phpVersion): array
    {
        $iniDir = PhpVersion::config($phpVersion)['ini_dir'];

        $extensions = [];

        foreach (glob("{$iniDir}/*.ini") ?: [] as $path) {
            $filename = basename($path);
            $extensions[] = ['filename' => $filename, 'name' => self::friendlyName($filename), 'enabled' => true];
        }

        foreach (glob("{$iniDir}/*.ini.disabled") ?: [] as $path) {
            $filename = basename($path, '.disabled');
            $extensions[] = ['filename' => $filename, 'name' => self::friendlyName($filename), 'enabled' => false];
        }

        usort($extensions, fn ($a, $b) => $a['name'] <=> $b['name']);

        return $extensions;
    }

    private static function friendlyName(string $filename): string
    {
        $name = preg_replace('/\.ini$/', '', $filename);

        return preg_replace('/^\d+-/', '', $name);
    }
}
