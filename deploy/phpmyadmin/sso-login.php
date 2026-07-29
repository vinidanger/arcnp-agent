<?php

/**
 * Ponte de login do SSO do phpMyAdmin (auth_type = signon). O Painel
 * gera um token HMAC-SHA256 assinado com o MESMO shared_secret já
 * usado pra assinar as requisições Painel -> Agent desse servidor
 * (uma cópia fica em pma-secret.php, ver deploy/README.md seção 15) —
 * nunca um segredo novo. Token de validade curta e uso único: a
 * assinatura sozinha não bastaria contra um link que vaze (histórico
 * do navegador, proxy, log), então cada nonce só pode ser consumido
 * uma vez (arquivo em NONCE_DIR).
 *
 * Roda como script PHP puro (sem framework) porque precisa estar na
 * MESMA pool php-fpm do phpMyAdmin — é isso que garante as duas partes
 * lerem/escreverem a mesma sessão "SignonSession".
 */

require __DIR__.'/pma-secret.php'; // define PMA_SSO_SECRET

const TOKEN_TTL_SECONDS = 60;
const NONCE_DIR = '/var/lib/pma-sso/nonces';

function reject(string $message): never
{
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

$token = $_GET['token'] ?? '';
$parts = explode('.', $token, 2);

if (count($parts) !== 2) {
    reject('Token inválido.');
}

[$payload, $signature] = $parts;

$expectedSignature = hash_hmac('sha256', $payload, PMA_SSO_SECRET);

if (! hash_equals($expectedSignature, $signature)) {
    reject('Assinatura inválida.');
}

$decoded = base64_decode($payload, true);
$data = $decoded === false ? null : json_decode($decoded, true);

if (! is_array($data) || ! isset($data['u'], $data['p'], $data['exp'], $data['n'])) {
    reject('Token malformado.');
}

if (time() > (int) $data['exp']) {
    reject('Link expirado — gere um novo no Painel.');
}

if (! is_dir(NONCE_DIR) && ! mkdir(NONCE_DIR, 0700, true) && ! is_dir(NONCE_DIR)) {
    reject('Falha interna (nonce dir).');
}

$nonce = preg_replace('/[^a-zA-Z0-9]/', '', (string) $data['n']);
$nonceFile = NONCE_DIR.'/'.$nonce;

if ($nonce === '' || file_exists($nonceFile)) {
    reject('Link já utilizado — gere um novo no Painel.');
}

file_put_contents($nonceFile, (string) time(), LOCK_EX);

session_name('SignonSession');
session_start();
$_SESSION['PMA_single_signon_user'] = $data['u'];
$_SESSION['PMA_single_signon_password'] = $data['p'];
session_write_close();

header('Location: /');
exit;
