<?php

namespace Gogol\VpsManager\App\Helpers;

use Gogol\VpsManager\App\Application;

class MySQL extends Application
{
    protected $mysqli;

    public function connect()
    {
        if ( $this->mysqli )
            return $this->mysqli;

        return $this->mysqli = new \mysqli("localhost", "root", null);
    }

    public function dbName($domain)
    {
        return preg_replace("/[^a-z0-9]+/i", '_', $domain);;
    }

    /**
     * Create new database and user
     * @param  string $domain
     * @return response
     */
    public function createDatabase($domain)
    {
        if ( ! isValidDomain($domain) )
            return $this->response->wrongDomainName();

        $database = $this->dbName($domain);
        $password = getRandomPassword();

        $this->connect()->query('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        $this->connect()->query('GRANT ALL PRIVILEGES ON `'.$database.'`.* to `'.$database.'`@`localhost` identified by \''.$password.'\'');
        $this->connect()->query('flush privileges');

        return $this->response()
                    ->success("<info>MySQL databáza úspešne vytvorená</info>\nDatabáza\Používateľ: <comment>$database</comment>\nHeslo: <comment>$password</comment>");
    }

    /**
     * Delete database and user
     * @param  string $domain
     * @return response
     */
    public function removeDatabaseWithUser($domain)
    {
        if ( ! isValidDomain($domain) )
            return $this->response()->wrongDomainName();

        $database = $this->dbName($domain);

        if ( ! $this->connect()->select_db($database) )
            return $this->response()->success('Database <comment>'.$database.'</comment> does not exists. In this case will not be deleted.');

        $query1 = $this->connect()->query('drop database `'.$database.'`');
        $query2 = $this->connect()->query('delete from mysql.user where user=\''.$database.'\'');

        $this->connect()->query('flush privileges');

        if ( !($query1 == true && $query2 == true) )
            return $this->response()->error('<error>Database and user '.$database.' could not be deleted</error>.');

        return $this->response()->success('<info>Mysql database and user</info> <comment>'.$database.'</comment> <info>has been successfuly removed.</info>');
    }
}

?>