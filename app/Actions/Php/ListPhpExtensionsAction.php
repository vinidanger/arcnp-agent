<?php

namespace App\Actions\Php;

use App\Actions\Contracts\AgentAction;
use App\Support\PhpExtensionList;
use App\Support\PhpVersion;

class ListPhpExtensionsAction implements AgentAction
{
    public function isAsync(): bool
    {
        return false;
    }

    public function execute(array $payload): array
    {
        $phpVersion = (string) ($payload['php_version'] ?? '');
        PhpVersion::config($phpVersion);

        return ['extensions' => PhpExtensionList::forVersion($phpVersion)];
    }
}
