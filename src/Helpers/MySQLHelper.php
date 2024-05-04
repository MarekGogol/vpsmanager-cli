<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;
use Exception;

class MySQLHelper extends Application
{
    protected $mysqli;

    public function connect()
    {
        if ($this->mysqli) {
            return $this->mysqli;
        }

        return $this->mysqli = new \mysqli('localhost', $this->config('mysql_user', 'root'), $this->config('mysql_pass', null));
    }

    public function dbName($domain)
    {
        return preg_replace('/[^a-z0-9]+/i', '_', $domain);
    }

    public function getHost()
    {
        return $this->config('mysql_host', 'localhost');
    }

    /*
     * Check if given db name is valid
     */
    public function isValidDBName($name)
    {
        return preg_match('/[0-9a-zA-Z$_]+/', $name, $matches) === 1 && $matches[0] == $name;
    }

    /**
     * Create new database and user
     * @param  string $domain
     * @return response
     */
    public function createDatabase($domain)
    {
        if (!($this->isValidDBName($domain) || isValidDomain($domain))) {
            return $this->response->wrongDomainName();
        }

        $database = $this->dbName($domain);
        $password = getRandomPassword();

        //Check if db exists
        try {
            if ($this->connect()->select_db($database)) {
                return $this->response()->success('User and database <comment>' . $database . '</comment> already exists.');
            }
        } catch (Exception $e) {
            //..
        }

        $host = $this->getHost();

        $this->connect()->query('CREATE DATABASE IF NOT EXISTS `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        $this->connect()->query('CREATE USER `' . $database . '`@`'.$host.'` IDENTIFIED WITH caching_sha2_password BY \'' . $password . '\'');
        $this->connect()->query('GRANT ALL PRIVILEGES ON `' . $database . '`.* TO `' . $database . '`@`'.$host.'`');
        // $this->connect()->query('GRANT ALL PRIVILEGES ON `'.$database.'`.* to `'.$database.'`@`'.$host.'` identified by \''.$password.'\'');
        $this->connect()->query('FLUSH PRIVILEGES');

        return $this->response()->success("<info>MySQL database has been successfuly created.</info>\nDatabase\User: <comment>$database</comment>\nPassword: <comment>$password</comment>");
    }

    /**
     * Reset database user password
     * @param  string $domain
     * @return response
     */
    public function resetPasswordDatabase($domain)
    {
        if (!($this->isValidDBName($domain) || isValidDomain($domain))) {
            return $this->response->wrongDomainName();
        }

        $database = $this->dbName($domain);
        $password = getRandomPassword();

        //Check if db exists
        if (!$this->connect()->select_db($database)) {
            return $this->response()->success('User and database <comment>' . $database . '</comment> does not exists.');
        }

        $this->connect()->query('ALTER USER `'.$database.'`@`'.$this->getHost().'` IDENTIFIED BY \''.$password.'\'');
        $this->connect()->query('FLUSH PRIVILEGES');

        return $this->response()->success("<info>MySQL password has been successfuly changed.</info>\nDatabase\User: <comment>$database</comment>\nPassword: <comment>$password</comment>");
    }

    /**
     * Delete database and user
     * @param  string $domain
     * @return response
     */
    public function removeDatabaseWithUser($domain)
    {
        if (!($this->isValidDBName($domain) || isValidDomain($domain))) {
            return $this->response()->wrongDomainName();
        }

        $database = $this->dbName($domain);

        if (!$this->connect()->select_db($database)) {
            return $this->response()->success('Database <comment>' . $database . '</comment> does not exists. In this case will not be deleted.');
        }

        $query1 = $this->connect()->query('DROP DATABASE `' . $database . '`');
        $query2 = $this->connect()->query('DELETE FROM mysql.user WHERE user=\'' . $database . '\'');
        $query2 = $this->connect()->query('DROP USER `' . $database . '`@`'.$this->getHost().'`;');

        $this->connect()->query('FLUSH PRIVILEGES');

        if (!($query1 == true && $query2 == true)) {
            return $this->response()->error('<error>Database and user ' . $database . ' could not be deleted</error>.');
        }

        return $this->response()->success('<info>Mysql database and user</info> <comment>' . $database . '</comment> <info>has been successfuly removed.</info>');
    }
}

?>
