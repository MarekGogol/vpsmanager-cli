<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;
use mysqli;
use mysqli_sql_exception;

class MySQLHelper extends Application
{
    /**
     * The MySQL connection.
     *
     * @var \mysqli|null
     */
    protected $mysqli;

    /**
     * Get the MySQL connection.
     *
     * @return \mysqli
     *
     * @throws \mysqli_sql_exception
     */
    public function connect(): mysqli
    {
        if ($this->mysqli) {
            return $this->mysqli;
        }

        // Throw exceptions on errors regardless of the PHP defaults
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        return $this->mysqli = new mysqli('localhost', $this->config('mysql_user', 'root'), $this->config('mysql_pass'));
    }

    /**
     * Convert the domain into the database name.
     *
     * @param  string  $domain
     * @return string
     */
    public function dbName($domain): string
    {
        return preg_replace('/[^a-z0-9]+/i', '_', $domain);
    }

    /**
     * Get the host the database users are allowed to connect from.
     *
     * @return string
     */
    public function getHost(): string
    {
        return $this->config('mysql_host', 'localhost');
    }

    /**
     * Determine if the given database name is valid.
     *
     * @param  string|null  $name
     * @return bool
     */
    public function isValidDBName($name): bool
    {
        return is_string($name) && preg_match('/^[0-9a-zA-Z$_]+$/', $name) === 1;
    }

    /**
     * Determine if the given database exists.
     *
     * @param  string  $database
     * @return bool
     */
    public function databaseExists($database): bool
    {
        try {
            return $this->connect()->select_db($database);
        } catch (mysqli_sql_exception $e) {
            return false;
        }
    }

    /**
     * Create a new database and user.
     *
     * @param  string  $domain
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function createDatabase($domain): Response
    {
        if (! ($this->isValidDBName($domain) || isValidDomain($domain))) {
            return $this->response()->wrongDomainName();
        }

        $database = $this->dbName($domain);
        $password = getRandomPassword();
        $host = $this->getHost();

        // Check if database exists
        if ($this->databaseExists($database)) {
            return $this->response()->success('User and database <comment>'.$database.'</comment> already exist.');
        }

        try {
            $this->connect()->query('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
            $this->connect()->query('CREATE USER `'.$database.'`@`'.$host.'` IDENTIFIED WITH caching_sha2_password BY \''.$password.'\'');
            $this->connect()->query('GRANT ALL PRIVILEGES ON `'.$database.'`.* TO `'.$database.'`@`'.$host.'`');
            $this->connect()->query('FLUSH PRIVILEGES');
        } catch (mysqli_sql_exception $e) {
            return $this->response()->error('MySQL database <comment>'.$database.'</comment> could not be created: '.$e->getMessage());
        }

        return $this->response()->success(
            "<info>MySQL database has been successfully created.</info>\nDatabase/User: <comment>$database</comment>\nPassword: <comment>$password</comment>"
        );
    }

    /**
     * Reset the password of the database user.
     *
     * @param  string  $domain
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function resetPasswordDatabase($domain): Response
    {
        if (! ($this->isValidDBName($domain) || isValidDomain($domain))) {
            return $this->response()->wrongDomainName();
        }

        $database = $this->dbName($domain);
        $password = getRandomPassword();

        // Check if database exists
        if (! $this->databaseExists($database)) {
            return $this->response()->error('User and database <comment>'.$database.'</comment> do not exist.');
        }

        try {
            $this->connect()->query('ALTER USER `'.$database.'`@`'.$this->getHost().'` IDENTIFIED BY \''.$password.'\'');
            $this->connect()->query('FLUSH PRIVILEGES');
        } catch (mysqli_sql_exception $e) {
            return $this->response()->error('MySQL password for user <comment>'.$database.'</comment> could not be changed: '.$e->getMessage());
        }

        return $this->response()->success(
            "<info>MySQL password has been successfully changed.</info>\nDatabase/User: <comment>$database</comment>\nPassword: <comment>$password</comment>"
        );
    }

    /**
     * Delete the database and its user.
     *
     * @param  string  $domain
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function removeDatabaseWithUser($domain): Response
    {
        if (! ($this->isValidDBName($domain) || isValidDomain($domain))) {
            return $this->response()->wrongDomainName();
        }

        $database = $this->dbName($domain);

        if (! $this->databaseExists($database)) {
            return $this->response()->success('Database <comment>'.$database.'</comment> does not exist, so it will not be deleted.');
        }

        try {
            $this->connect()->query('DROP DATABASE `'.$database.'`');
            $this->connect()->query('DROP USER IF EXISTS `'.$database.'`@`'.$this->getHost().'`');
            $this->connect()->query('FLUSH PRIVILEGES');
        } catch (mysqli_sql_exception $e) {
            return $this->response()->error('Database and user <comment>'.$database.'</comment> could not be deleted: '.$e->getMessage());
        }

        return $this->response()->success('<info>MySQL database and user</info> <comment>'.$database.'</comment> <info>have been successfully removed.</info>');
    }
}
