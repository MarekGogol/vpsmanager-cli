<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use \DateTime;
use \DateInterval;

class Backup extends Application
{
    private $log = [];

    private $ignoreFile = '.backups_ignore';

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

        $this->log('ERROR', $message);

        return $this->response();
    }

    /*
     * Save error/message into log
     */
    private function log($type, $message)
    {
        $this->log[] = $message;

        $text = date('Y-m-d H:i:s').' ['.$type.']'.' - '.$message;

        file_put_contents($this->config('backup_path').'/logs.log', $text."\n", FILE_APPEND);
    }

    /*
     * Check if all required services all installed
     */
    public function checkRequirements()
    {
        $missing = [];

        foreach (['zip', 'rsync'] as $apt)
        {
            if ( ! $this->server()->isInstalledExtension($apt) )
                $missing[] = $apt;
        }

        return $missing;
    }

    /*
     * Check if mysql connection works
     */
    private function testMysql()
    {
        $user = $this->config('mysql_user', 'root');
        $pass = $this->config('mysql_pass', '');

        exec('mysql -u'.$user.' -p"'.$pass.'" -e "show databases;" -s --skip-column-names', $output, $return_var);

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

        $dbUser = $this->config('mysql_user', 'root');
        $dbPass = $this->config('mysql_pass', '');

        //Backup databases
        foreach ($backup_databases as $database)
        {
            $filename = $backup_path.'/'.$database.'.sql.gz';

            $this->response()->success('Saving and compressing <comment>'.$database.'</comment> database.')->writeln();
            exec('(mysqldump -u'.$dbUser.' -p"'.$dbPass.'" '.$database.' || rm -f "'.$filename.'") | gzip > "'.$filename.'"', $output, $return_var);
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

        //If we dont want backup any directories
        if ( ($backup_directories = $this->config('backup_directories')) == '-' )
            return $this->response();

        $directories = array_filter(explode(';', $backup_directories));

        $errors = [];

        foreach ($directories as $dir)
        {
            $this->response()->success('Saving and compressing <comment>'.$dir.'</comment> directory.')->writeln();

            //Split commands into directory and other parameters
            $dir_parts = explode(' ', $dir);
            $dir = $dir_parts[0];

            //If except commands are available in path
            $except = count($dir_parts) > 1 ? implode(' ', array_slice($dir_parts, 1)) : null;

            //Zip and save directory
            if ( ! $this->zipDirectory($dir, $backup_path.'/'.$this->getZipName($dir), $except) )
                $errors[] = $dir;
        }

        //Log and send all directories which could not be saved
        if ( count($errors) > 0 )
            return $this->sendError('Could not backup directories: '.implode(', ', $errors));

        return $this->response()->success('<info>Folders has been successfully backed up.</info>');
    }

    /*
     Escape directory from backup ignore file list
     */
    private function escapeDirectory($directory)
    {
        $directory = preg_replace('/[^a-z\.A-Z\_\-0-9\/]/', "", $directory);

        return $directory;
    }

    /*
     * Get www data exclude directories
     */
    private function getExcludeDirectories($domain, $exclude = '')
    {
        $exclude_domain_root = ['.config/\*', '.cache/\*', '.local/\*', '.npm/\*', '.pm2/\*', '.nano/\*', '.gnupg/\*', '.bash_history', '.selected_editor'];
        $exclude_global_folters = ['node_modules/\*', 'vendor/\*', 'cache/\*', 'laravel.log'];

        $domain_path = $this->getUserDirPath($domain);

        $dataDir = $this->getWebDirectory();

        //Exclude gloval folders
        foreach ($exclude_global_folters as $item) {
            $exclude .= ' -x */\\'.$item;
        }

        $isWebDirWithData = file_exists($domain_path.$dataDir);
        $webDir = $isWebDirWithData ? $domain_path.$dataDir : $domain_path;
        $relativePath = ($isWebDirWithData ? trim($dataDir, '/') : $domain);

        //Check if ignore file exists, and exclude directories from given file
        if ( file_exists($ignore_file = $webDir.'/'.$this->ignoreFile) )
        {
            $ignore = array_filter(explode("\n", file_get_contents($ignore_file)));

            foreach ($ignore as $item)
            {
                $item = trim($item, '/');

                //Exclude file or firectory
                if ( is_file($webDir.'/'.$item) )
                    $exclude .= ' -x '.$relativePath.'/'.$this->escapeDirectory($item);
                else if ( is_dir($webDir.'/'.$item) )
                    $exclude .= ' -x '.$relativePath.'/'.$this->escapeDirectory($item).'/\*';
            }
        }

        //Exclude uneccessary directories in root domain folder
        foreach ($exclude_domain_root as $item)
            $exclude .= ' -x '.$relativePath.'/'.$item;

        return $exclude;
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

            $webPath = $www_path.'/'.$domain;

            //If is new directory structure, then copy all files from data folder
            if ( file_exists($dataWebpath = $webPath.$this->getWebDirectory()) )
                $webPath = $dataWebpath;

            //Zip and save directory
            if ( ! $this->zipDirectory(
                $webPath,
                $backup_path.'/'.$this->getZipName($domain),
                $this->getExcludeDirectories($domain)
            ) ) {
                $errors[] = $domain;
            }
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
            $weeks2_before = (new DateTime)->setTimestamp(strtotime('Monday this week'))->sub(new DateInterval('P1W'));

            //Allow everything from today
            if ( $date >= $yesterday )
                $allow[] = $date_dir;

            //Allow last 2 backups from last 2 mondays
            if (
                $date >= $weeks2_before && $date->format('D') == 'Mon' && $date < $yesterday
                && !array_key_exists($key = 'week-'.$date->format('y-m-d'), $allow)
            ) {
                $allow[$key] = $date_dir;
            }
        }

        //Remove uneccessary backups
        foreach (array_diff($backups, array_unique($allow)) as $dir) {
            exec('rm -rf "'.$backup_path.'/'.$dir.'"');
        }
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
        $cmd = 'ssh -o StrictHostKeyChecking=no -o BatchMode=yes -o ConnectTimeout=5 '.$this->config('remote_user').'@'.$this->config('remote_server').' -i '.$this->getRemoteRSAKeyPath().' -t "exit" 2>&1';

        exec($cmd, $output, $return_var);

        return $return_var == 0;
    }

    public function testMailServer()
    {
        // Instantiation and passing `true` enables exceptions
        return $this->sendMail('Test mail', 'Hello :), your email server is working.');
    }

    public function sendMail($subject, $message)
    {
        $mail = new PHPMailer(true);

        try {
            $host = explode(':', $this->config('email_server'));
            $server_name = $this->config('backup_server_name', 'VPS Manager');

            //Server settings
            $mail->isSMTP();                                        // Set mailer to use SMTP
            $mail->Host       = $host[0];                           // Specify main and backup SMTP servers
            $mail->SMTPAuth   = true;                               // Enable SMTP authentication
            $mail->Username   = $this->config('email_username');    // SMTP username
            $mail->Password   = $this->config('email_password');    // SMTP password
            $mail->SMTPSecure = $host[1] == 25 ? 'tls' : 'ssl';     // Enable TLS encryption, `ssl` also accepted
            $mail->Port       = $host[1];                           // TCP port to connect to

            //Recipients
            $mail->setFrom($this->config('email_username'), 'VPS Manager');
            $mail->addAddress($this->config('email_receiver'));

            // Content
            $mail->isHTML(true);
            $mail->Subject = ($subject ?: 'Backups') . ' - '.$server_name;
            $mail->Body    = $message;
            $mail->Body   .= '<br><br>';
            $mail->Body   .= 'Server: <strong>'.$server_name.'</strong><br>';
            $mail->Body   .= 'Date: '.date('d.m.Y H:i:s').'<br>';
            $mail->Body   .= '<br><img src="https://media.giphy.com/media/EFXGvbDPhLoWs/giphy.gif" alt="">';

            $mail->send();

            return true;
        } catch (Exception $e) {
            return $mail->ErrorInfo;
        }
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
            $allowed_backups = array_slice($backups, -$this->config('remote_backup_limit'));

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
        if ( ! $this->config('remote_backups') )
            return;

        $remote_server = $this->config('remote_server');

        $this->response()->success('Syncing backups to remote <comment>'.$remote_server.'</comment> server.')->writeln();

        $backup_path = $this->getBackupPath();
        $exclude = $this->getExcludedRsyncBackups($backup_path);

        //If ssh key does not exists
        if ( !file_exists($this->getRemoteRSAKeyPath()) ){
            $this->sendError('SSH Key for authentication with remove server does not exists.');
            return;
        }

        exec($cmd = 'rsync -avzP --delete --delete-excluded '.implode(' ', $exclude).' -e \'ssh -o StrictHostKeyChecking=no -i '.$this->getRemoteRSAKeyPath().'\' '.$this->getBackupPath().'/* '.$this->config('remote_user').'@'.$remote_server.':'.$this->config('remote_path'), $output, $return_var);

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

    //Send email if notifications are enabled
    //and any error happens
    private function sendNotification()
    {
        if ( $this->config('email_notifications') && count($this->log) > 0 )
            $this->sendMail('Error notification', implode('<br>', $this->log));
    }

    public function getFreeDiskSpace()
    {
        return round(disk_free_space('/')/1024/1000/1000, 1);
    }

    /*
     * Run all types of backups
     */
    public function perform($backup = [])
    {
        $start = microtime(true);

        //If memory is under 2 gigabytes, send notification
        if ( ($free_space = $this->getFreeDiskSpace()) <= 2 )
        {
            $this->sendError('Available disk space is '.$free_space.'GB. Please expand you disk space.');
            $this->removeOldBackups();
        }

        //Backup databases
        if ( $this->isAllowed($backup, 'databases') )
            $this->backupDatabases()->writeln();

        //Backup directories
        if ( $this->isAllowed($backup, 'dirs') )
            $this->backupDirectories()->writeln();

        //Backup www data
        if ( $this->isAllowed($backup, 'www') )
            $this->backupWWWData()->writeln();

        $this->removeOldBackups();
        $this->sendLocalBackupsToRemoteServer();
        $this->sendNotification();

        $this->log('INFO', 'Backup end | DB:'.($this->isAllowed($backup, 'databases') ? 'YES' : 'NO').' | WWW:'.($this->isAllowed($backup, 'www') ? 'YES' : 'NO').' | DIRS:'.($this->isAllowed($backup, 'dirs') ? 'YES' : 'NO').' | '.round((microtime(true)-$start)/60, 1).' Min.');

        return $this->response()->success('Full backup has been successfullu performed.');
    }
}
?>