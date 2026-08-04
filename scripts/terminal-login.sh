#!/bin/bash
# Comando que o ttyd embrulha (ver deploy/README.md seção 34) — só
# pede o usuário Linux e entrega pro sshd de verdade autenticar, com a
# senha de "Acesso SSH" que a conta já tem configurada no Painel.
# Nenhum sistema de credencial novo: quem autentica é o sshd, do jeito
# de sempre. Roda como "nobody" (ver unit do ttyd) — sem privilégio
# nenhum, só encaminha pro ssh local.
set -euo pipefail

read -rp "Usuário: " username

# localhost sempre é o mesmo host (não dá pra spoofar por rede), então
# pular a checagem de host-key aqui é seguro e evita o ssh tentar
# escrever known_hosts num home que o usuário "nobody" não tem.
exec ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -t localhost -l "$username"
