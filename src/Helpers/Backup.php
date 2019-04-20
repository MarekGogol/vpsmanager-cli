<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;
use \DateTime;
use \DateInterval;

class Backup extends Application
{
    private function getBackupPath($directory = null, $storage = 'local')
    {
        $path = trim_end($this->config('backup_path').'/'.($storage ? $storage.'/' : '').$directory, '/');

        return $path;
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
        $backup_path = $this->createIfNotExists('databases');

        $does_not_backup = ['information_schema', 'performance_schema'];

        //Get just databases which should be backed up
        $backup_databases = array_diff($test_result['databases'], $does_not_backup);

        //Backup databases
        foreach ($backup_databases as $database)
        {
            $filename = $backup_path.'/'.$database.'.sql.gz';

            $this->response()->success('Saving and compressing <comment>'.$database.'</comment> database.')->writeln();
            exec('(mysqldump -uroot '.$database.' || rm -f "'.$filename.'") | gzip > "'.$filename.'"', $output, $return_var);
        }

        //Check if is available at least one backup
        $backed_up = $this->getTree($backup_path);

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
        $directory = $this->getBackupPath($directory).'/'.date('Y-m-d_H-00-00');

        if ( ! file_exists($directory) )
            exec('mkdir -p "'.$directory.'"');

        return $directory;
    }

    /*
     * Return directory tree
     */
    private function getTree($path)
    {
        if ( ! file_exists($path) )
            return [];

        return array_values(array_diff(scandir($path), ['.', '..']));
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
        $backup_path = $this->createIfNotExists('dirs');

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
    // cd /var/www/html/../ && zip -r /root/backups/2019-04-18_16/www/html.zip html -x */\node_modules/\* -x */\vendor/\* -x */\cache/\* -x */\laravel.log
    public function backupWWWData()
    {
        $backup_path = $this->createIfNotExists('www');

        $www_path = $this->config('backup_www_path');

        $directories = $this->getTree($www_path);

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
     * Check if is backup type allowed
     */
    private function isAllowed($config, $key)
    {
        return array_key_exists($key, $config) && $config[$key] == true;
    }

    /*
     * Removes old database backups in intervals:
     * 1 day => does not remove anything
     * 7 days => backups one per day
     * 1 month => 1 backup per week
     */
    public function removeOldDatabaseBackups($storage)
    {
        $backup_path = $this->getBackupPath('databases', $storage);
        $backups = $this->getTree($backup_path);

        asort($backups);

        //Remove files which are not in format of backups
        foreach ($backups as $key => $date_dir) {
            if ( ! DateTime::createFromFormat('Y-m-d_H-i-s', $date_dir) )
                unset($backups[$key]);
        }

        //In every case of date does not delete 2 last backups. Because if backups stops
        //you will have 2 last backups...
        $allow = array_slice($backups, -2);

        //Delete uneccessary backups
        foreach ($backups as $date_dir)
        {
            $date = DateTime::createFromFormat('Y-m-d_H-i-s', $date_dir);

            $yesterday = (new DateTime)->sub(new DateInterval('P1D'));
            $week_before = (new DateTime)->sub(new DateInterval('P1W'));
            $month_before = (new DateTime)->sub(new DateInterval('P1M'));

            //Allow everything from last 24 hours
            if ( $date >= $yesterday )
                $allow[] = $date_dir;

            //Allow one backup per day from last week
            if ( $date >= $week_before && $date < $yesterday )
                $allow[$date->format('y-m-d')] = $date_dir;

            //Allow one backup per week from last month interval
            if (
                $date >= $month_before && $date < $week_before
                && !array_key_exists($key = 'week-'.$date->format('y-m-W'), $allow)
            ) {
                $allow[$key] = $date_dir;
            }
        }

        //Remove uneccessary backups
        foreach (array_diff($backups, array_unique($allow)) as $dir)
            exec('rm -rf "'.$backup_path.'/'.$dir.'"');
    }

    /*
     * Removes old data backups in intervals:
     * 1 day => does not remove anything
     * 2 weeks => 1 backup from start of week
     */
    public function removeOldDataBackups($storage, $type)
    {
        $backup_path = $this->getBackupPath($type, $storage);
        $backups = $this->getTree($backup_path);

        asort($backups);

        //Remove files which are not in format of backups
        foreach ($backups as $key => $date_dir) {
            if ( ! DateTime::createFromFormat('Y-m-d_H-i-s', $date_dir) )
                unset($backups[$key]);
        }

        //In every case of date does not delete last backup. Because if backups stops
        //you will have last backup...
        $allow = array_slice($backups, -1);

        //Delete uneccessary backups
        foreach ($backups as $date_dir)
        {
            $date = DateTime::createFromFormat('Y-m-d_H-i-s', $date_dir);

            $yesterday = (new DateTime)->setTime(0, 0, 0);
            $weeks2_before = (new DateTime)->sub(new DateInterval('P2W'));

            //Allow everything from today
            if ( $date >= $yesterday )
                $allow[] = $date_dir;

            //Allow first one backup per week in year
            if (
                $date >= $weeks2_before && $date < $yesterday
                && !array_key_exists($key = 'week-'.$date->format('y-m-W'), $allow)
            ) {
                $allow[$key] = $date_dir;
            }
        }

        //Remove uneccessary backups
        foreach (array_diff($backups, array_unique($allow)) as $dir)
            exec('rm -rf "'.$backup_path.'/'.$dir.'"');
    }

    /*
     * Remove all old unnecessary backups
     */
    public function removeOldBackups()
    {
        $backup_path = $this->getBackupPath(null, null);
        $storages = $this->getTree($backup_path);

        //Remove from all storages
        foreach ($storages as $storage)
        {
            $this->removeOldDatabaseBackups($storage);
            $this->removeOldDataBackups($storage, 'dirs');
            $this->removeOldDataBackups($storage, 'www');
        }

        $this->response()->success('<info>Old backups has been removed.</info>')->writeln();
    }

    public function testRemoteServer()
    {
        $cmd = 'ssh -o StrictHostKeyChecking=accept-new -o BatchMode=yes -o ConnectTimeout=5 '.$this->config('remote_user').'@'.$this->config('remote_server').' -i '.$this->getRemoteRSAKeyPath().' -t "exit" 2>&1';

        exec($cmd, $output, $return_var);

        return $return_var == 0;
    }

    /*
     * Get all folders which should be excluded from backup
     */
    private function getExcludedRsyncBackups($backup_path)
    {
        $exclude = [];

        foreach ($this->getTree($backup_path) as $dir)
        {
            $backups = $this->getTree($backup_path.'/'.$dir);

            //Get last x backups
            $allowed_backups = array_slice($backups, -2);

            //Build rsync params
            $exclude_folders = array_map(function($backup) use($dir){
                return '--exclude \''.$dir.'/'.$backup.'\'';
            }, array_diff($backups, $allowed_backups));

            $exclude = array_merge($exclude, $exclude_folders);
        }

        return $exclude;
    }

    /*
     * Send local backups data to remote server
     */
    public function sendLocalBackupsToRemoteServer()
    {
        if ( ! $this->config('backup_remote') )
            return;

        $remote_server = $this->config('remote_server');

        $this->response()->success('Syncing backups to remote <comment>'.$remote_server.'</comment> server.')->writeln();

        $backup_path = $this->getBackupPath();
        $exclude = $this->getExcludedRsyncBackups($backup_path);

        exec($cmd = 'rsync -avzP --delete --delete-excluded '.implode(' ', $exclude).' -e \'ssh -i '.$this->getRemoteRSAKeyPath().'\' '.$this->getBackupPath().'/* '.$this->config('remote_user').'@'.$remote_server.':'.$this->config('remote_path'), $output, $return_var);

        if ( $return_var == 0 )
            $this->response()->success('<info>All backups has been synced to remote</info> <comment>'.$remote_server.'</comment> <info>server</info>')->writeln();
        else
            $this->sendError('Files could not be synced to other server.');
    }

    /*
     * Get rsync remote SSH key
     */
    public function getRemoteRSAKeyPath()
    {
        return $this->config('backup_path').'/.ssh/id_rsa';
    }

    /*
     * Save remote server private key
     */
    public function setRemoteKey($value)
    {
        $key_path = $this->getRemoteRSAKeyPath();

        file_put_contents($key_path, $value);
        exec('chmod 600 '.$key_path);
    }

    /*
     * Run all types of backups
     */
    public function perform($backup = [])
    {
        //Backup databases
        if (
            $this->isAllowed($backup, 'databases')
            && ($response = $this->backupDatabases()->writeln())->isError()
        ) {
            return $response;
        }

        //Backup directories
        if (
            $this->isAllowed($backup, 'dirs')
            && ($response = $this->backupDirectories()->writeln())->isError()
        ) {
            return $response;
        }

        //Backup www data
        if (
            $this->isAllowed($backup, 'www')
            && ($response = $this->backupWWWData()->writeln())->isError()
        ) {
            return $response;
        }

        $this->removeOldBackups();
        $this->sendLocalBackupsToRemoteServer();

        return $this->response()->success('Full backup has been successfullu performed.');
    }
}
?>