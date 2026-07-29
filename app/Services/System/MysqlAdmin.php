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

        DB::connection('mysql_admin')->statement(
            "CREATE USER IF NOT EXISTS `{$username}`@'localhost' IDENTIFIED BY ?",
            [$password]
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
}
