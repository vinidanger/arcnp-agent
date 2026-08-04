<?php

namespace App\Support;

use App\Services\System\TemplateRenderer;

/**
 * Salts gerados localmente via random_bytes() (hex — sem aspas/barra,
 * seguro pra embutir num literal PHP de aspas simples sem escaping)
 * em vez de depender do serviço externo api.wordpress.org/secret-key —
 * uma chamada de rede a mais que poderia falhar por motivo fora do
 * nosso controle.
 */
class WpConfigTemplate
{
    private const SALT_KEYS = [
        'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key',
        'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt',
    ];

    public static function render(string $dbName, string $dbUsername, string $dbPassword): string
    {
        $variables = [
            'db_name' => $dbName,
            'db_username' => $dbUsername,
            'db_password' => $dbPassword,
        ];

        foreach (self::SALT_KEYS as $key) {
            $variables[$key] = bin2hex(random_bytes(32));
        }

        return (new TemplateRenderer())->render('wp-config', $variables);
    }
}
