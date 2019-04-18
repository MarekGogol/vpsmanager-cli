<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

class Backup extends Application
{
    private function getBackupPath()
    {
        return $this->config('backup_path') . '/' . date('Y-m-d_H');
    }

    /*
     * Send error email
     */
    private function sendError($message)
    {
        //Send mail if is in cron
        $this->response()->message('<error>'.$message.'</error>')->writeln();

        return $this->response();
    }

    /*
     * Check if mysql connection works
     */
    private function testMysql()
    {
        exec('mysql -uroot -e "show databases;" -s --skip-column-names', $output, $return_var);

        return [
            'result' => $return_var == 0,
            'databases' => $output,
        ];
    }

    public function backupDatabases()
    {
        if ( ! ($test_result = $this->testMysql())['result'] )
            return $this->sendError('Could not connect to database.');

        //Where store backup
        $backup_path = $this->createIfNotExists($this->getBackupPath().'/mysql');

        $does_not_backup = ['information_schema', 'performance_schema'];

        //Get just databases which should be backed up
        $backup_databases = array_diff($test_result['databases'], $does_not_backup);

        //Backup databases
        foreach ($backup_databases as $database)
        {
            $filename = $backup_path.'/'.$database.'.sql.gz';

            $this->response()->success('Saving and compressing <comment>'.$database.'</comment> database.')->writeln();
            exec('(mysqldump '.$database.' || rm -f "'.$filename.'") | gzip > "'.$filename.'"', $output, $return_var);
        }

        //Check if is available at least one backup
        $backed_up = array_diff(scandir($backup_path), ['.', '..']);

        //Get just databases without unnecessary ones...
        $all_databases = array_map(function($item){
            return $item . '.sql.gz';
        }, $backup_databases);

        //Compane if some databases are missing from backup
        if ( count($missing = array_diff($all_databases, $backed_up)) > 0 )
            return $this->sendError('Databases could not be backed up: '.implode(' ', $missing));

        return $this->response()->success('<info>Databases has been successfully backed up.</info>');
    }

    /*
     * Zip directory and return if has been saved
     */
    public function zipDirectory($dir, $where, $except = null)
    {
        $path = explode('/', $dir);
        $directory = end($path);

        exec('cd '.$dir.'/../ && zip -r '.$where.' '.$directory.' '.$except, $output, $return_var);

        return $return_var == 0;
    }

    /*
     * Create database if not exists
     */
    private function createIfNotExists($directory)
    {
        if ( ! file_exists($directory) )
            exec('mkdir -p "'.$directory.'"');

        return $directory;
    }

    private function getZipName($name)
    {
        $name = preg_replace('/^\//', '', $name);
        $name = str_replace('/', '_', $name);

        return $name.'.zip';
    }

    /*
     * Backup all required directories and zip them
     */
    public function backupDirectories()
    {
        $backup_path = $this->createIfNotExists($this->getBackupPath().'/folders');

        $directories = explode(';', $this->config('backup_directories'));

        $errors = [];

        foreach ($directories as $dir)
        {
            $this->response()->success('Saving and compressing <comment>'.$dir.'</comment> directory.')->writeln();

            //Zip and save directory
            if ( ! $this->zipDirectory($dir, $backup_path.'/'.$this->getZipName($dir)) )
                $errors[] = $dir;
        }

        //Log and send all directories which could not be saved
        if ( count($errors) > 0 )
            return $this->sendError('Could not backup directories: '.implode(', ', $errors));

        return $this->response()->success('<info>Folders has been successfully backed up.</info>');
    }

    /*
     * Backup all required directories and zip them
     */
    public function backupWWWData()
    {
        $backup_path = $this->createIfNotExists($this->getBackupPath().'/www');

        $www_path = $this->config('www_path');

        $directories = array_diff(scandir($www_path), ['.', '..']);

        $errors = [];

        foreach ($directories as $domain)
        {
            $this->response()->success('Saving and compressing <comment>'.$domain.'</comment> domain.')->writeln();

            //Zip and save directory
            if ( ! $this->zipDirectory(
                $www_path.'/'.$domain,
                $backup_path.'/'.$this->getZipName($domain),
                '-x */\node_modules/\* -x */\vendor/\* -x */\cache/\* -x */\laravel.log'
            ) )
                $errors[] = $domain;
        }

        //Log and send all directories which could not be saved
        if ( count($errors) > 0 )
            return $this->sendError('Could not backup directories: '.implode(', ', $errors));

        return $this->response()->success('<info>Folders has been successfully backed up.</info>');
    }

    /*
     * Run all types of backups
     */
    public function perform()
    {
        //Check errors
        if ( ($response = $this->backupDatabases()->writeln())->isError() )
            return $response;

        //Check errors
        if ( ($response = $this->backupDirectories()->writeln())->isError() )
            return $response;

        //Check errors
        if ( ($response = $this->backupWWWData()->writeln())->isError() )
            return $response;

        return $this->response()->success('Full backup has been successfullu performed.');
    }
}
?>