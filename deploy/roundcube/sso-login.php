<?php

/**
 * Ponte de login do SSO do Roundcube. Diferente do phpMyAdmin (que
 * suporta auth_type=signon nativo), o Roundcube não tem um mecanismo
 * de sessão pré-autenticada — ele sempre autentica contra o IMAP com
 * usuário/senha reais a cada login. Então em vez de forjar sessão,
 * essa ponte decodifica um token assinado (mesmo esquema do
 * DatabaseSsoToken do phpMyAdmin, ver app/Support/MailboxSsoToken no
 * Painel) e devolve uma página que AUTO-SUBMETE o formulário de login
 * padrão do Roundcube com usuário/senha preenchidos — o Roundcube
 * autentica normalmente a partir daí, sem plugin nenhum.
 */

require __DIR__.'/sso-secret.php'; // define MAIL_SSO_SECRET

const TOKEN_TTL_SECONDS = 60;
const NONCE_DIR = '/var/lib/roundcube-sso/nonces';

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

$expectedSignature = hash_hmac('sha256', $payload, MAIL_SSO_SECRET);

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

$user = htmlspecialchars((string) $data['u'], ENT_QUOTES, 'UTF-8');
$pass = htmlspecialchars((string) $data['p'], ENT_QUOTES, 'UTF-8');

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html>
<body onload="document.forms[0].submit()">
    <form method="POST" action="/?_task=login">
        <input type="hidden" name="_task" value="login">
        <input type="hidden" name="_action" value="login">
        <input type="hidden" name="_user" value="<?= $user ?>">
        <input type="hidden" name="_pass" value="<?= $pass ?>">
    </form>
</body>
</html>
