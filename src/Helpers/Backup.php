<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Carbon\Carbon;
use Gogol\VpsManagerCLI\Application;
use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

class Backup extends Application
{
    /**
     * Timezone used for backup directory names and logs.
     *
     * @var string
     */
    private const TIMEZONE = 'Europe/Bratislava';

    /**
     * Format of dated backup directory names.
     *
     * @var string
     */
    private const DIRECTORY_FORMAT = 'Y-m-d_H-i-s';

    /**
     * Minimal free disk space buffer in MB required above the size of the latest backup.
     *
     * @var int
     */
    private const DISK_SPACE_BUFFER = 2000;

    /**
     * Error messages logged during the current backup run.
     *
     * @var array
     */
    private $log = [];

    /**
     * Name of the file with paths excluded from website backup.
     *
     * @var string
     */
    private $ignoreFile = '.backups_ignore';

    /**
     * Date of the current backup run.
     *
     * @var \Carbon\Carbon|null
     */
    private $date;

    /**
     * Get the current backup date.
     *
     * @return \Carbon\Carbon
     */
    public function now(): Carbon
    {
        if ($this->date) {
            return $this->date->copy();
        }

        return Carbon::now(self::TIMEZONE);
    }

    /**
     * Get backup path for given directory and storage.
     *
     * @param  string|null  $directory
     * @param  string|null  $storage
     * @return string
     */
    private function getBackupPath($directory = null, $storage = 'local'): string
    {
        return trim_end($this->config('backup_path').'/'.($storage ? $storage.'/' : '').$directory, '/');
    }

    /**
     * Write error into console and log.
     *
     * @param  string  $message
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    private function sendError($message): Response
    {
        $this->response()
            ->message('<error>'.$message.'</error>')
            ->writeln();

        $this->log('ERROR', $message);

        return $this->response()->error($message);
    }

    /**
     * Save error/message into log file.
     *
     * @param  string  $type
     * @param  string  $message
     * @return void
     */
    private function log($type, $message): void
    {
        $this->log[] = $message;

        $text = $this->now()->format('Y-m-d H:i:s').' ['.$type.'] - '.$message;

        file_put_contents($this->config('backup_path').'/logs.log', $text."\n", FILE_APPEND);
    }

    /**
     * Get required packages which are not installed.
     *
     * @return array
     */
    public function checkRequirements(): array
    {
        $missing = [];

        foreach (['zip', 'tar', 'rsync'] as $apt) {
            if (! $this->server()->isInstalledExtension($apt)) {
                $missing[] = $apt;
            }
        }

        return $missing;
    }

    /**
     * Check if MySQL connection works and return available databases.
     *
     * @return array
     */
    private function testMysql(): array
    {
        $user = $this->config('mysql_user', 'root');
        $pass = $this->config('mysql_pass', '');

        exec('mysql -u'.$user.' '.($pass ? '-p"'.$pass.'"' : '').' -e "show databases;" -s --skip-column-names', $output, $return_var);

        return [
            'result' => $return_var == 0,
            'databases' => $output,
        ];
    }

    /**
     * Backup all databases.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function backupDatabases(): Response
    {
        if (! ($test_result = $this->testMysql())['result']) {
            return $this->sendError('Could not connect to database.');
        }

        if (! $this->hasEnoughSpace(['databases'], 'Could not backup databases.')) {
            return $this->response();
        }

        $backup_path = $this->createIfNotExists('databases');

        // Backup all databases except system ones
        $backup_databases = array_diff($test_result['databases'], ['information_schema', 'performance_schema']);

        $user = $this->config('mysql_user', 'root');
        $pass = $this->config('mysql_pass', '');

        foreach ($backup_databases as $database) {
            $filename = $backup_path.'/'.$database.'.sql.gz';

            $this->response()
                ->success('Saving and compressing <comment>'.$database.'</comment> database.')
                ->writeln();

            // On mysqldump failure the dump file is removed, so it will be reported as missing
            exec(
                '(nice -n 15 ionice -c2 -n7 mysqldump --single-transaction --quick --routines --events --triggers -u'.$user.' '.
                ($pass ? '-p"'.$pass.'"' : '').' '.$database.' || rm -f "'.$filename.'") | gzip -1 > "'.$filename.'"',
            );
        }

        $backed_up = $this->getTree($backup_path);

        $all_databases = array_map(fn ($database) => $database.'.sql.gz', $backup_databases);

        // Check if some databases are missing in the backup
        if (count($missing = array_diff($all_databases, $backed_up)) > 0) {
            return $this->sendError('Databases could not be backed up: '.implode(' ', $missing));
        }

        return $this->response()->success('<info>Databases have been successfully backed up.</info>');
    }

    /**
     * Compress directory into tar.gz archive.
     *
     * @param  string  $dir
     * @param  string  $where
     * @param  string|null  $except  Tar exclude arguments, e.g. "--exclude='*\/vendor/*'"
     * @return bool
     */
    public function tarDirectory(string $dir, string $where, ?string $except = null): bool
    {
        $dir = rtrim($dir, '/');
        $directory = basename($dir);
        $baseDir = dirname($dir);

        $excludePart = $except ? ' '.$except : '';

        // Streaming tar into gzip with lower priority and fast compression
        $cmd = sprintf('cd %s && nice -n 15 ionice -c2 -n7 tar -cf -%s %s | gzip -1 > %s', escapeshellarg($baseDir), $excludePart, escapeshellarg($directory), escapeshellarg($where));

        exec($cmd, $output, $returnVar);

        return $returnVar === 0;
    }

    /**
     * Create dated backup directory if it does not exist.
     *
     * @param  string  $directory
     * @return string
     */
    private function createIfNotExists($directory): string
    {
        $directory = $this->getBackupPath($directory).'/'.$this->now()->format('Y-m-d_H-00-00');

        if (! file_exists($directory)) {
            exec('mkdir -p "'.$directory.'"');
        }

        return $directory;
    }

    /**
     * Get directory listing.
     *
     * @param  string|null  $path
     * @return array
     */
    private function getTree($path): array
    {
        if (! $path || ! file_exists($path)) {
            return [];
        }

        return array_values(array_diff(scandir($path), ['.', '..']));
    }

    /**
     * Get archive file name from path.
     *
     * @param  string  $name
     * @return string
     */
    private function getTarName($name): string
    {
        return str_replace('/', '_', ltrim($name, '/')).'.tar.gz';
    }

    /**
     * Backup all configured server directories.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function backupDirectories(): Response
    {
        // Skip when no directories should be backed up
        if (($backup_directories = (string) $this->config('backup_directories')) == '-') {
            return $this->response();
        }

        if (! $this->hasEnoughSpace(['dirs'], 'Could not backup server directories.')) {
            return $this->response();
        }

        $backup_path = $this->createIfNotExists('dirs');

        $errors = [];

        foreach (array_filter(explode(';', $backup_directories)) as $dir) {
            $this->response()
                ->success('Saving and compressing <comment>'.$dir.'</comment> directory.')
                ->writeln();

            // Split directory path and additional tar parameters
            $dir_parts = explode(' ', $dir);
            $dir = $dir_parts[0];

            $except = count($dir_parts) > 1 ? implode(' ', array_slice($dir_parts, 1)) : null;

            if (! $this->tarDirectory($dir, $backup_path.'/'.$this->getTarName($dir), $except)) {
                $errors[] = $dir;
            }
        }

        if (count($errors) > 0) {
            return $this->sendError('Could not backup directories: '.implode(', ', $errors));
        }

        return $this->response()->success('<info>Directories have been successfully backed up.</info>');
    }

    /**
     * Get tar exclude arguments for website backup.
     *
     * @param  string  $domain
     * @param  string  $domain_path
     * @return string
     */
    private function getExcludeDirectories($domain, $domain_path): string
    {
        $exclude = '';

        // Things excluded from user's home directory
        $exclude_domain_root = ['.config/*', '.cache/*', '.local/*', '.npm/*', '.pm2/*', '.nano/*', '.gnupg/*', '.bash_history', '.selected_editor'];

        // Things excluded anywhere inside project
        $exclude_global_folders = ['node_modules/*', 'vendor/*', 'cache/*', 'laravel.log'];

        $dataDir = $this->getWebDirectory();

        foreach ($exclude_global_folders as $item) {
            $exclude .= ' --exclude='.escapeshellarg('*/'.$item);
        }

        $isWebDirWithData = file_exists($domain_path.$dataDir);
        $webDir = $isWebDirWithData ? $domain_path.$dataDir : $domain_path;
        $relativePath = $isWebDirWithData ? trim($dataDir, '/') : $domain;

        // Read ignore file with custom excluded paths
        if (file_exists($ignore_file = $webDir.'/'.$this->ignoreFile)) {
            foreach (explode("\n", file_get_contents($ignore_file)) as $item) {
                $item = trim($item);

                if ($item === '' || str_starts_with($item, '#')) {
                    continue;
                }

                $pattern = $relativePath.'/'.trim($item, '/');

                if (is_dir($webDir.'/'.$item)) {
                    $pattern .= '/*';
                }

                $exclude .= ' --exclude='.escapeshellarg($pattern);
            }
        }

        // Exclude home directory garbage inside domain root
        foreach ($exclude_domain_root as $item) {
            $exclude .= ' --exclude='.escapeshellarg($relativePath.'/'.$item);
        }

        return $exclude;
    }

    /**
     * Backup all websites data.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function backupWWWData(): Response
    {
        if (! $this->hasEnoughSpace(['www'], 'Could not backup WWW directories.')) {
            return $this->response();
        }

        $backup_path = $this->createIfNotExists('www');

        $www_path = $this->config('backup_www_path');

        $errors = [];

        foreach ($this->getTree($www_path) as $domain) {
            $this->response()
                ->success('Saving and compressing <comment>'.$domain.'</comment> domain.')
                ->writeln();

            $domainPath = $www_path.'/'.$domain;
            $webPath = $domainPath;

            // With new directory structure, backup only data folder
            if (file_exists($dataWebPath = $webPath.$this->getWebDirectory())) {
                $webPath = $dataWebPath;
            }

            $except = $this->getExcludeDirectories($domain, $domainPath);

            if (! $this->tarDirectory($webPath, $backup_path.'/'.$this->getTarName($domain), $except)) {
                $errors[] = $domain;
            }
        }

        if (count($errors) > 0) {
            return $this->sendError('Could not backup directories: '.implode(', ', $errors));
        }

        return $this->response()->success('<info>WWW directories have been successfully backed up.</info>');
    }

    /**
     * Check if backup type is allowed.
     *
     * @param  array  $config
     * @param  string  $key
     * @return bool
     */
    private function isAllowed($config, $key): bool
    {
        return array_key_exists($key, $config) && $config[$key] == true;
    }

    /**
     * Get dated backup directories sorted from oldest to newest.
     *
     * @param  string  $backup_path
     * @return array
     */
    private function getDatedBackups($backup_path): array
    {
        $backups = array_values(array_filter(
            $this->getTree($backup_path),
            fn ($dir) => $this->parseBackupDate($dir) !== null,
        ));

        sort($backups);

        return $backups;
    }

    /**
     * Parse date from backup directory name.
     *
     * @param  string  $dir
     * @return \Carbon\Carbon|null
     */
    private function parseBackupDate($dir): ?Carbon
    {
        try {
            return Carbon::createFromFormat(self::DIRECTORY_FORMAT, $dir, self::TIMEZONE) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Remove old database backups in intervals.
     *
     * Last 24 hours: keep everything.
     * Last 7 days: keep one backup per day.
     * Last month: keep one backup per week.
     *
     * @param  string  $storage
     * @return void
     */
    public function removeOldDatabaseBackups($storage): void
    {
        $backup_path = $this->getBackupPath('databases', $storage);
        $backups = $this->getDatedBackups($backup_path);

        // Always keep 2 latest backups, so there will be something left if backups stop working
        $allow = array_slice($backups, -2);

        $yesterday = $this->now()->subDay();
        $week_before = $this->now()->subWeek();
        $month_before = $this->now()->subMonth();

        foreach ($backups as $date_dir) {
            $date = $this->parseBackupDate($date_dir);

            // Keep everything from last 24 hours
            if ($date >= $yesterday) {
                $allow[] = $date_dir;
            }

            // Keep one backup per day from last week
            if ($date >= $week_before && $date < $yesterday) {
                $allow[$date->format('y-m-d')] = $date_dir;
            }

            // Keep one backup per week from last month
            if ($date >= $month_before && $date < $week_before && ! array_key_exists($key = 'week-'.$date->format('y-m-W'), $allow)) {
                $allow[$key] = $date_dir;
            }
        }

        foreach (array_diff($backups, array_unique($allow)) as $dir) {
            exec('rm -rf "'.$backup_path.'/'.$dir.'"');
        }
    }

    /**
     * Remove old data backups in intervals.
     *
     * Today: keep the latest backup.
     * Last 2 weeks: keep backups from Mondays.
     * Only configured count of newest backups is kept.
     *
     * @param  string  $storage
     * @param  string  $type
     * @return void
     */
    public function removeOldDataBackups($storage, $type): void
    {
        $backup_path = $this->getBackupPath($type, $storage);
        $backups = $this->getDatedBackups($backup_path);

        $allow = [];

        // Always keep the latest backup, so there will be something left if backups stop working
        if ($latestBackup = end($backups)) {
            $allow['today'] = $latestBackup;
        }

        $today = $this->now()->startOfDay();
        $weeks2_before = $this->now()->startOfWeek()->subWeek();

        foreach ($backups as $date_dir) {
            $date = $this->parseBackupDate($date_dir);

            // Keep the latest backup from today
            if ($date >= $today) {
                $allow['today'] = $date_dir;
            }

            // Keep backups from last 2 Mondays
            if ($date >= $weeks2_before && $date->isMonday() && $date < $today && ! array_key_exists($key = 'week-'.$date->format('y-m-d'), $allow)) {
                $allow[$key] = $date_dir;
            }
        }

        // Keep only configured count of newest backups
        $latestBackups = array_values($allow);
        sort($latestBackups);
        $whitelistedBackups = array_slice($latestBackups, -max(1, (int) $this->config('backup_www_max_limit', 2)));

        foreach (array_diff($backups, $whitelistedBackups) as $dir) {
            exec('rm -rf "'.$backup_path.'/'.$dir.'"');
        }
    }

    /**
     * Remove all old unnecessary backups from all storages.
     *
     * @return void
     */
    public function removeOldBackups(): void
    {
        foreach ($this->getTree($this->getBackupPath(null, null)) as $storage) {
            // Skip files and hidden directories (e.g. .ssh, logs.log) in backup root
            if (str_starts_with($storage, '.') || ! is_dir($this->getBackupPath(null, $storage))) {
                continue;
            }

            $this->removeOldDatabaseBackups($storage);
            $this->removeOldDataBackups($storage, 'dirs');
            $this->removeOldDataBackups($storage, 'www');
        }

        $this->response()
            ->success('<info>Old backups have been removed.</info>')
            ->writeln();
    }

    /**
     * Test SSH connection to remote backup server.
     *
     * @return bool
     */
    public function testRemoteServer(): bool
    {
        $cmd = 'ssh -o StrictHostKeyChecking=no -o BatchMode=yes -o ConnectTimeout=5 '.
            $this->config('remote_user').'@'.$this->config('remote_server').
            ' -i '.$this->getRemoteRSAKeyPath().' -t "exit" 2>&1';

        exec($cmd, $output, $return_var);

        return $return_var == 0;
    }

    /**
     * Send test email.
     *
     * @return bool|string
     */
    public function testMailServer(): bool|string
    {
        return $this->sendMail('Test mail', 'Hello :), your email server is working.');
    }

    /**
     * Send email notification.
     *
     * @param  string|null  $subject
     * @param  string  $message
     * @return bool|string True on success, error message on failure
     */
    public function sendMail($subject, $message): bool|string
    {
        // Passing true enables exceptions
        $mail = new PHPMailer(true);

        try {
            $host = explode(':', (string) $this->config('email_server'));
            $port = (int) ($host[1] ?? 465);
            $server_name = $this->config('backup_server_name', 'VPS Manager');

            // Server settings
            $mail->isSMTP();
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Host = $host[0];
            $mail->SMTPAuth = true;
            $mail->Username = $this->config('email_username');
            $mail->Password = $this->config('email_password');
            $mail->SMTPSecure = $port == 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $port;

            // Recipients
            $mail->setFrom($this->config('email_username'), 'VPS Manager');
            $mail->addAddress($this->config('email_receiver'));

            // Content
            $mail->isHTML(true);
            $mail->Subject = ($subject ?: 'Backups').' - '.$server_name;
            $mail->Body = $message.'<br><br>'.
                'Server: <strong>'.$server_name.'</strong><br>'.
                'Date: '.Carbon::now(self::TIMEZONE)->format('d.m.Y H:i:s').'<br>'.
                '<br><img src="https://media.giphy.com/media/EFXGvbDPhLoWs/giphy.gif" alt="">';

            $mail->send();

            return true;
        } catch (MailerException $e) {
            return $mail->ErrorInfo ?: $e->getMessage();
        }
    }

    /**
     * Get rsync exclude arguments for backups which should not be synced to remote server.
     *
     * @param  string  $backup_path
     * @return array
     */
    private function getExcludedRsyncBackups($backup_path): array
    {
        $exclude = [];
        $limit = max(1, (int) $this->config('remote_backup_limit', 2));

        foreach ($this->getTree($backup_path) as $dir) {
            $backups = $this->getTree($backup_path.'/'.$dir);

            $allowed_backups = array_slice($backups, -$limit);

            $exclude_folders = array_map(
                fn ($backup) => '--exclude \''.$dir.'/'.$backup.'\'',
                array_diff($backups, $allowed_backups),
            );

            $exclude = array_merge($exclude, $exclude_folders);
        }

        return $exclude;
    }

    /**
     * Sync local backups to remote server.
     *
     * @return void
     */
    public function sendLocalBackupsToRemoteServer(): void
    {
        if (! $this->config('remote_backups')) {
            return;
        }

        $remote_server = $this->config('remote_server');

        $this->response()
            ->success('Syncing backups to remote <comment>'.$remote_server.'</comment> server.')
            ->writeln();

        if (! file_exists($this->getRemoteRSAKeyPath())) {
            $this->sendError('SSH key for authentication with remote server does not exist.');

            return;
        }

        $backup_path = $this->getBackupPath();
        $exclude = $this->getExcludedRsyncBackups($backup_path);

        exec(
            'rsync -avzP --delete --delete-excluded '.implode(' ', $exclude).
            ' -e \'ssh -o StrictHostKeyChecking=no -i '.$this->getRemoteRSAKeyPath().'\' '.
            $backup_path.'/* '.$this->config('remote_user').'@'.$remote_server.':'.$this->config('remote_path'),
            $output,
            $return_var,
        );

        if ($return_var == 0) {
            $this->response()
                ->success('<info>All backups have been synced to remote</info> <comment>'.$remote_server.'</comment> <info>server.</info>')
                ->writeln();
        } else {
            $this->sendError('Files could not be synced to remote server.');
        }
    }

    /**
     * Get SSH private key path used for remote server.
     *
     * @return string
     */
    public function getRemoteRSAKeyPath(): string
    {
        return $this->config('backup_path').'/.ssh/id_rsa';
    }

    /**
     * Save remote server private key.
     *
     * @param  string  $value
     * @return void
     */
    public function setRemoteKey($value): void
    {
        $key_path = $this->getRemoteRSAKeyPath();

        file_put_contents($key_path, $value);
        exec('chmod 600 '.$key_path);
    }

    /**
     * Send email with errors if notifications are enabled.
     *
     * @return void
     */
    private function sendNotification(): void
    {
        if ($this->config('email_notifications') && count($this->log) > 0) {
            $this->sendMail('Error notification', implode('<br>', $this->log));
        }
    }

    /**
     * Get free disk space in MB.
     *
     * @param  string  $path
     * @return float
     */
    public function getFreeDiskSpace($path = '/'): float
    {
        return round((float) @disk_free_space($path) / 1000000, 1);
    }

    /**
     * Run all allowed types of backups.
     *
     * @param  array  $backup
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function perform($backup = []): Response
    {
        $this->date = $backup['date'] ?? $this->now();
        $this->log = [];

        $this->response()
            ->success('<info>Performing backup for '.$this->now()->format('d.m.Y H:i').'.</info>')
            ->writeln();

        $start = microtime(true);

        if ($this->isAllowed($backup, 'databases')) {
            $this->backupDatabases()->writeln();
        }

        if ($this->isAllowed($backup, 'dirs')) {
            $this->backupDirectories()->writeln();
        }

        if ($this->isAllowed($backup, 'www')) {
            $this->backupWWWData()->writeln();
        }

        $this->removeOldBackups();
        $this->sendLocalBackupsToRemoteServer();
        $this->sendNotification();

        $this->log(
            'INFO',
            'Backup end'.
            ' | DB:'.($this->isAllowed($backup, 'databases') ? 'YES' : 'NO').
            ' | WWW:'.($this->isAllowed($backup, 'www') ? 'YES' : 'NO').
            ' | DIRS:'.($this->isAllowed($backup, 'dirs') ? 'YES' : 'NO').
            ' | '.round((microtime(true) - $start) / 60, 1).' Min.',
        );

        return $this->response()->success('Full backup has been successfully performed.');
    }

    /**
     * Get total size in MB of the latest backups of given types.
     *
     * @param  array  $sumDirectories
     * @return float
     */
    private function getLatestTotalBackupSize($sumDirectories): float
    {
        $totalSum = 0;

        foreach ($sumDirectories as $directory) {
            $backups = $this->getDatedBackups($this->getBackupPath($directory));

            if (! $latestBackup = end($backups)) {
                continue;
            }

            $totalSum += round(getDirectorySize($this->getBackupPath($directory).'/'.$latestBackup) / 1000000);
        }

        return $totalSum;
    }

    /**
     * Check if there is enough disk space for the next backup.
     *
     * @param  array  $sumDirectories
     * @param  string  $error
     * @return bool
     */
    private function hasEnoughSpace($sumDirectories, $error): bool
    {
        $latestBackupSize = $this->getLatestTotalBackupSize($sumDirectories);

        $freeSpace = $this->getFreeDiskSpace($this->config('backup_path'));

        // Free space must be larger than the latest backup plus buffer
        if ($freeSpace <= $latestBackupSize + self::DISK_SPACE_BUFFER) {
            $this->sendError(
                $error.' Disk space is too low for backup of '.$latestBackupSize.'MB (size of the previous backup). '.
                'Current free disk space is '.$freeSpace.'MB. '.
                'Please expand your disk space to at least '.self::DISK_SPACE_BUFFER.'MB above the size of the latest backup.',
            );

            return false;
        }

        return true;
    }
}
