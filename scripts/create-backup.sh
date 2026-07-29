#!/bin/bash
# Uso: create-backup.sh <username> <retention> <tmp_dump_dir> [db1 db2 ...]
#
# tmp_dump_dir contém os dumps de banco já gerados SEM privilégio (o
# próprio Agent já tem acesso de leitura ao MySQL via MysqlAdmin) — o
# que exige privilégio aqui é: tarar o public_html (dono é o usuário da
# conta, o Agent não tem acesso de leitura nele) e mover os dumps pra
# dentro do home do usuário com posse correta. Também aplica retenção
# (mantém só as N cópias mais recentes de cada tipo) e devolve em JSON
# o que foi criado, pra Action repassar ao Painel.
set -euo pipefail

USERNAME="${1:-}"
RETENTION="${2:-}"
TMP_DUMP_DIR="${3:-}"
shift 3 || true
DATABASES=("$@")

if [[ ! "$USERNAME" =~ ^[a-z][a-z0-9]{2,31}$ ]]; then
    echo "Username inválido: $USERNAME" >&2
    exit 1
fi

if [[ ! "$RETENTION" =~ ^[1-9][0-9]*$ ]]; then
    echo "Retenção inválida: $RETENTION" >&2
    exit 1
fi

HOME_DIR="/home/$USERNAME"
BACKUP_DIR="$HOME_DIR/backups"

if [[ ! -d "$HOME_DIR" ]]; then
    echo "Home não existe: $HOME_DIR" >&2
    exit 1
fi

mkdir -p "$BACKUP_DIR"
chown "$USERNAME:$USERNAME" "$BACKUP_DIR"
chmod 750 "$BACKUP_DIR"

# O Agent (usuário arcnpagent, sem privilégio) precisa ler esses
# arquivos depois pra servir o download — mesmo padrão de ACL já usado
# pro nginx alcançar public_html sem afrouxar a permissão base 750 do
# home. Sem isso, o dono continua sendo só o usuário da conta.
setfacl -m u:arcnpagent:rx "$HOME_DIR"
setfacl -m u:arcnpagent:rx "$BACKUP_DIR"

TIMESTAMP=$(date +%Y%m%d%H%M%S)
FILES_ARCHIVE="files-$TIMESTAMP.tar.gz"

tar -czf "$BACKUP_DIR/$FILES_ARCHIVE" -C "$HOME_DIR" public_html
chown "$USERNAME:$USERNAME" "$BACKUP_DIR/$FILES_ARCHIVE"
setfacl -m u:arcnpagent:r "$BACKUP_DIR/$FILES_ARCHIVE"

CREATED=("$FILES_ARCHIVE")

for DB in "${DATABASES[@]:-}"; do
    [[ -z "$DB" ]] && continue

    if [[ ! "$DB" =~ ^[a-z][a-z0-9_]{2,63}$ ]]; then
        echo "Nome de banco inválido: $DB" >&2
        exit 1
    fi

    SRC="$TMP_DUMP_DIR/db-$DB.sql.gz"

    if [[ ! -f "$SRC" ]]; then
        echo "Dump não encontrado: $SRC" >&2
        exit 1
    fi

    DEST_NAME="db-$DB-$TIMESTAMP.sql.gz"
    mv "$SRC" "$BACKUP_DIR/$DEST_NAME"
    chown "$USERNAME:$USERNAME" "$BACKUP_DIR/$DEST_NAME"
    setfacl -m u:arcnpagent:r "$BACKUP_DIR/$DEST_NAME"
    CREATED+=("$DEST_NAME")
done

prune() {
    local pattern="$1"
    # shellcheck disable=SC2231
    ls -1t $BACKUP_DIR/$pattern 2>/dev/null | tail -n +$((RETENTION + 1)) | xargs -r rm -f
}

prune "files-*.tar.gz"
for DB in "${DATABASES[@]:-}"; do
    [[ -z "$DB" ]] && continue
    prune "db-$DB-*.sql.gz"
done

printf '['
for i in "${!CREATED[@]}"; do
    NAME="${CREATED[$i]}"
    SIZE=$(stat -c %s "$BACKUP_DIR/$NAME")
    [[ $i -gt 0 ]] && printf ','
    printf '{"filename":"%s","size":%d}' "$NAME" "$SIZE"
done
printf ']\n'
