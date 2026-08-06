<?php

namespace App\Services\System;

use App\Support\MysqlIdentifier;
use Illuminate\Support\Facades\DB;

/**
 * Único ponto do Agent que fala com o MySQL/MariaDB administrativo.
 * Usa a conexão `mysql_admin` (usuário dedicado, só CREATE/DROP/CREATE
 * USER — nunca root de verdade, ver config/database.php). Identificadores
 * (nome de banco/usuário) não aceitam bind em DDL, por isso passam por
 * MysqlIdentifier::validate() antes de qualquer interpolação.
 */
class MysqlAdmin
{
    public function createDatabase(string $dbName): void
    {
        MysqlIdentifier::validate($dbName);

        DB::connection('mysql_admin')->statement(
            "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    }

    public function dropDatabase(string $dbName): void
    {
        MysqlIdentifier::validate($dbName);

        DB::connection('mysql_admin')->statement("DROP DATABASE IF EXISTS `{$dbName}`");
    }

    public function createUser(string $username, string $password): void
    {
        MysqlIdentifier::validate($username);

        // CREATE USER é DDL — MySQL/MariaDB não aceita bind de parâmetro
        // (?) nessa posição, só em DML. O escape correto aqui é via
        // PDO::quote() do driver, não interpolação manual.
        $quotedPassword = DB::connection('mysql_admin')->getPdo()->quote($password);

        DB::connection('mysql_admin')->statement(
            "CREATE USER IF NOT EXISTS `{$username}`@'localhost' IDENTIFIED BY {$quotedPassword}"
        );
    }

    public function dropUser(string $username): void
    {
        MysqlIdentifier::validate($username);

        DB::connection('mysql_admin')->statement("DROP USER IF EXISTS `{$username}`@'localhost'");
    }

    public function grantAllPrivileges(string $dbName, string $username): void
    {
        MysqlIdentifier::validate($dbName);
        MysqlIdentifier::validate($username);

        DB::connection('mysql_admin')->statement("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO `{$username}`@'localhost'");
        DB::connection('mysql_admin')->statement('FLUSH PRIVILEGES');
    }

    /**
     * Concede acesso a TODOS os bancos cujo nome comece com "{prefix}_"
     * (todos os bancos de uma conta, ver provisionDatabase() no Painel —
     * cada banco já nasce como "{linux_username}_{sufixo}") — usado só
     * pelo usuário "mestre" de uma conta, pra permitir login único no
     * phpMyAdmin listando todos os bancos de uma vez, em vez de um por
     * vez via o usuário dedicado de cada banco.
     *
     * O alvo de um GRANT em nível de banco aceita curinga de padrão SQL
     * (_ e %) mesmo entre crases — documentado assim no próprio manual do
     * MySQL. É isso que torna o wildcard possível aqui, mas também exige
     * escapar o "_" literal que separa prefixo e sufixo (\_), senão ele
     * também casaria com qualquer outro caractere naquela posição —
     * "joao_%" sem escape casaria com "joaox%" de outra conta também.
     */
    public function grantAllPrivilegesWildcard(string $prefix, string $username): void
    {
        MysqlIdentifier::validate($prefix);
        MysqlIdentifier::validate($username);

        $pattern = $prefix.'\\_%';

        DB::connection('mysql_admin')->statement("GRANT ALL PRIVILEGES ON `{$pattern}`.* TO `{$username}`@'localhost'");
        DB::connection('mysql_admin')->statement('FLUSH PRIVILEGES');
    }

    /**
     * Substituto caseiro do "database governor" do CloudLinux — só a
     * parte que o MariaDB de fábrica já suporta nativamente (limite de
     * conexão simultânea e de query/hora por usuário, via sintaxe padrão
     * de ALTER USER ... WITH ...). CPU/IO de uma query específica dentro
     * do mysqld compartilhado fica fora de alcance sem ProxySQL/servidor
     * modificado — não é isso que este método faz.
     *
     * $maxConnections/$maxQueriesPerHour já chegam como inteiro validado
     * pela Action (nunca string vinda do payload sem passar por (int)),
     * então interpolar direto aqui é seguro — 0 é o valor nativo do
     * MySQL/MariaDB pra "sem limite" (mesma conversão null→0 que os
     * limites de cgroup já fazem com "infinity").
     */
    public function applyResourceLimits(string $username, int $maxConnections, int $maxQueriesPerHour): void
    {
        MysqlIdentifier::validate($username);

        DB::connection('mysql_admin')->statement(
            "ALTER USER `{$username}`@'localhost' WITH MAX_USER_CONNECTIONS {$maxConnections} MAX_QUERIES_PER_HOUR {$maxQueriesPerHour}"
        );
    }
}
