<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Carbon\Carbon;
use Gogol\VpsManagerCLI\Application;
use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

// Backups of the databases, websites and server directories into a restic repository, local or on an SFTP server.
class Backup extends Application
{
    /**
     * Timezone used for backup names and logs.
     *
     * @var string
     */
    private const TIMEZONE = 'Europe/Bratislava';

    /**
     * Minimal free disk space in MB, of a local repository or for the cache of restic.
     *
     * @var int
     */
    private const DISK_SPACE_BUFFER = 2000;

    /**
     * Lock held by a running backup:run or backup:maintenance, swap:refresh waits for its end.
     *
     * @var string
     */
    const LOCK = '/var/lib/vpsmanager/backup.lock';

    /**
     * Lock held by a running backup:maintenance, backup:run waits for its end instead of skipping.
     *
     * @var string
     */
    const MAINTENANCE_LOCK = '/var/lib/vpsmanager/backup-maintenance.lock';

    /**
     * Log of the backups.
     *
     * @var string
     */
    const LOG = '/var/log/vpsmanager/backup.log';

    /**
     * Logrotate configuration of the log.
     *
     * @var string
     */
    const LOGROTATE = '/etc/logrotate.d/vpsmanager-backup';

    /**
     * Retention of the databases: everything from the last day, then one backup per day, week and month.
     *
     * @var array
     */
    const KEEP_DATABASES = ['within' => '1d', 'daily' => 7, 'weekly' => 4, 'monthly' => 6];

    /**
     * Retention of the websites and the directories.
     *
     * @var array
     */
    const KEEP_DATA = ['daily' => 7, 'weekly' => 4, 'monthly' => 3];

    /**
     * The weekly check reads one of these parts of the data of the repository.
     *
     * @var int
     */
    const CHECK_PARTS = 20;

    /**
     * Types of the backups, by their tag in restic.
     *
     * @var array
     */
    const TYPES = ['databases', 'dirs', 'www'];

    /**
     * Error messages logged during the current backup run.
     *
     * @var array
     */
    private $log = [];

    /**
     * Databases, directories and users of the websites backed up by the current run, by type, for the monitor.
     *
     * @var array
     */
    private $backedUp = [];

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
     * Get the restic helper.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\Restic
     */
    public function restic(): Restic
    {
        return vpsManager()->restic();
    }

    /**
     * Get the host name of the snapshots of this server.
     *
     * @return string
     */
    public function host(): string
    {
        return (string) $this->config('backup_server_name', gethostname());
    }

    /**
     * Determine if the backups are configured with a restic repository.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return Restic::isConfigured($this->config());
    }

    /**
     * Acquire the lock of the backups.
     *
     * @param  bool  $wait  wait for a running backup instead of failing
     * @param  string  $path
     * @return resource|null the lock held until it is closed, null when another backup holds it
     */
    public static function lock(bool $wait = false, string $path = self::LOCK)
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        $lock = fopen($path, 'c');

        if (! $lock || ! flock($lock, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) {
            return null;
        }

        return $lock;
    }

    /**
     * Acquire the lock of a running backup:maintenance, held together with the lock of the backups.
     *
     * @param  string  $path
     * @return resource|null
     */
    public static function lockMaintenance(string $path = self::MAINTENANCE_LOCK)
    {
        return self::lock(true, $path);
    }

    /**
     * Determine if a lock is held by another process.
     *
     * @param  string  $path
     * @return bool
     */
    public static function isLocked(string $path): bool
    {
        if (! file_exists($path) || ! $lock = fopen($path, 'r')) {
            return false;
        }

        $locked = ! flock($lock, LOCK_SH | LOCK_NB);

        fclose($lock);

        return $locked;
    }

    /**
     * Determine if backup:maintenance is running.
     *
     * @return bool
     */
    public static function isMaintenanceRunning(): bool
    {
        return self::isLocked(self::MAINTENANCE_LOCK);
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
        if ($type === 'ERROR') {
            $this->log[] = $message;
        }

        if (! is_dir(dirname(self::LOG))) {
            @mkdir(dirname(self::LOG), 0750, true);
        }

        // The real time, now() is the start of the run which names the backups for the monitor
        @file_put_contents(self::LOG, Carbon::now(self::TIMEZONE)->format('Y-m-d H:i:s').' ['.$type.'] - '.$message."\n", FILE_APPEND);
    }

    /**
     * Write the logrotate configuration of the log.
     *
     * @return void
     */
    public function installLogrotate(): void
    {
        if (! is_dir(dirname(self::LOG))) {
            mkdir(dirname(self::LOG), 0750, true);
        }

        file_put_contents(self::LOGROTATE, self::LOG." {\n    monthly\n    rotate 12\n    compress\n    missingok\n    notifempty\n}\n");
    }

    /**
     * Get missing requirements of the backups.
     *
     * @return array
     */
    public function checkRequirements(): array
    {
        $missing = [];

        if (! Restic::isSupportedVersion($this->restic()->installedVersion())) {
            $missing[] = 'restic '.Restic::MINIMAL_VERSION.' or newer (installed by backup:setup)';
        }

        if ($this->backsUpDatabases() && ! trim((string) shell_exec('command -v mysqldump 2>/dev/null'))) {
            $missing[] = 'mysqldump (or turn off the databases with backup:setup)';
        }

        return $missing;
    }

    /**
     * Determine if a MySQL or MariaDB server is installed on this server.
     *
     * @return bool
     */
    public static function hasMysqlServer(): bool
    {
        return trim((string) shell_exec('command -v mysqld mariadbd 2>/dev/null')) !== '' || file_exists('/usr/sbin/mysqld') || file_exists('/usr/sbin/mariadbd');
    }

    /**
     * Determine if this server backs up its databases, e.g. not an application server with the databases elsewhere.
     *
     * @return bool
     */
    public function backsUpDatabases(): bool
    {
        return (bool) $this->config('backup_databases', self::hasMysqlServer());
    }

    /**
     * Log an error which stops the backups and send it by email, e.g. a server with the old tar.gz backups after an update.
     *
     * @param  string  $message
     * @return string the error
     */
    public function reportError(string $message): string
    {
        $this->log = [];
        $this->log('ERROR', $message);
        $this->sendNotification();

        return $message;
    }

    /**
     * Create a temporary MySQL option file with the credentials, so the password is not visible in the processes.
     *
     * @return string path of the file, remove it after use
     */
    public function createMysqlOptionFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vpsmanager-mysql-');
        chmod($path, 0600);

        $escape = fn ($value) => str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value);

        file_put_contents($path, "[client]\nuser=\"".$escape($this->config('mysql_user', 'root'))."\"\npassword=\"".$escape($this->config('mysql_pass', ''))."\"\n");

        return $path;
    }

    /**
     * Get all databases of the server except the system ones.
     *
     * @param  string  $optionFile
     * @return array|null null when MySQL is not reachable
     */
    private function getDatabases(string $optionFile): ?array
    {
        exec('mysql --defaults-extra-file='.escapeshellarg($optionFile).' -e "SHOW DATABASES;" -s --skip-column-names 2>/dev/null', $output, $code);

        if ($code !== 0) {
            return null;
        }

        return array_values(array_diff($output, ['information_schema', 'performance_schema']));
    }

    /**
     * Backup all databases, each into its own snapshot of /databases/{name}.sql.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function backupDatabases(): Response
    {
        $optionFile = $this->createMysqlOptionFile();

        try {
            if (($databases = $this->getDatabases($optionFile)) === null) {
                return $this->sendError('Could not connect to database.');
            }

            $errors = [];

            foreach ($databases as $database) {
                $this->startItem('Saving <comment>'.$database.'</comment> database.');

                // A failed mysqldump fails the backup, no snapshot is created
                $code = $this->restic()->niced()->run(array_merge(
                    ['backup', '--json', '--host', $this->host(), '--tag', 'databases', '--stdin-from-command', '--stdin-filename', 'databases/'.$database.'.sql', '--'],
                    Restic::NICE,
                    ['mysqldump', '--defaults-extra-file='.$optionFile, '--single-transaction', '--quick', '--routines', '--events', '--triggers', $database],
                ), $output, $error);

                $this->finishItem($code, $output);

                if ($code === 0) {
                    $this->backedUp['databases'][] = $database;
                } else {
                    $errors[] = $database.' ('.Restic::errorMessage($error).')';
                }
            }
        } finally {
            @unlink($optionFile);
        }

        if (count($errors) > 0) {
            return $this->sendError('Databases could not be backed up: '.implode(', ', $errors));
        }

        return $this->response()->success('<info>Databases have been successfully backed up.</info>');
    }

    /**
     * Backup all configured server directories into one snapshot.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function backupDirectories(): Response
    {
        // Skip when no directories should be backed up
        if (($directories = trim((string) $this->config('backup_directories'))) === '-' || $directories === '') {
            return $this->response();
        }

        $paths = $excludes = $missing = [];

        foreach (Restic::directoryEntries($directories) as $entry) {
            if (! file_exists($entry['path'])) {
                $missing[] = $entry['path'];

                continue;
            }

            $paths[] = $entry['path'];
            $excludes = array_merge($excludes, $entry['excludes']);
        }

        if ($paths) {
            $this->startItem('Saving directories <comment>'.implode(' ', $paths).'</comment>.');

            $code = $this->restic()->niced()->run(array_merge(
                ['backup', '--json', '--host', $this->host(), '--tag', 'dirs'],
                $this->excludeArguments($excludes),
                $paths,
            ), $output, $error);

            $this->finishItem($code, $output);

            if ($code !== 0) {
                return $this->sendError('Could not backup directories: '.Restic::errorMessage($error));
            }

            $this->backedUp['dirs'] = $paths;
        }

        if ($missing) {
            return $this->sendError('Directories do not exist: '.implode(', ', $missing));
        }

        return $this->response()->success('<info>Directories have been successfully backed up.</info>');
    }

    /**
     * Write the start of the backup of an item, its result follows on the same line.
     *
     * @param  string  $message
     * @return void
     */
    private function startItem(string $message): void
    {
        vpsManager()->getOutput()?->write($message);
    }

    /**
     * Write the result of the backup of an item: its size, the added data and the duration from the summary of restic.
     *
     * @param  int  $code
     * @param  string|null  $output  JSON output of restic backup
     * @return void
     */
    private function finishItem(int $code, ?string $output): void
    {
        if (! $console = vpsManager()->getOutput()) {
            return;
        }

        if ($code !== 0) {
            $console->writeln(' <error>Failed.</error>');

            return;
        }

        $console->writeln(($summary = Restic::backupSummary($output)) ? ' '.self::describeSummary($summary) : '');
    }

    /**
     * Describe the summary of a backup, e.g. "1.2 GB, added 3.4 MB in 5.2s".
     *
     * @param  array  $summary
     * @return string
     */
    public static function describeSummary(array $summary): string
    {
        $added = $summary['data_added_packed'] ?? $summary['data_added'] ?? null;

        return '<comment>'.self::formatBytes($summary['total_bytes_processed'] ?? null).'</comment>'.
            ($added !== null ? ', added '.self::formatBytes($added) : '').
            (isset($summary['total_duration']) ? ' in '.round($summary['total_duration'], 1).'s' : '');
    }

    /**
     * Format bytes for humans.
     *
     * @param  int|float|null  $bytes
     * @return string
     */
    public static function formatBytes($bytes): string
    {
        if ($bytes === null) {
            return '-';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;

        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }

        return round($bytes, $unit > 1 ? 1 : 0).' '.$units[$unit];
    }

    /**
     * Get the --exclude arguments of restic.
     *
     * @param  array  $excludes
     * @return array
     */
    private function excludeArguments(array $excludes): array
    {
        $arguments = [];

        foreach (array_unique($excludes) as $exclude) {
            $arguments[] = '--exclude='.$exclude;
        }

        return $arguments;
    }

    /**
     * Get the backed up directory of a website, only the data folder with the new directory structure.
     *
     * @param  string  $domain
     * @return string
     */
    public function getWebsitePath(string $domain): string
    {
        $domainPath = rtrim((string) $this->config('backup_www_path', '/var/www'), '/').'/'.$domain;

        if (file_exists($dataPath = $domainPath.$this->getWebDirectory())) {
            return $dataPath;
        }

        return $domainPath;
    }

    /**
     * Backup all websites, each into its own snapshot.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function backupWWWData(): Response
    {
        $wwwPath = rtrim((string) $this->config('backup_www_path', '/var/www'), '/');

        if (! is_dir($wwwPath)) {
            return $this->sendError('WWW directory '.$wwwPath.' does not exist.');
        }

        $errors = [];

        foreach (array_diff(scandir($wwwPath), ['.', '..']) as $domain) {
            if (! is_dir($wwwPath.'/'.$domain)) {
                continue;
            }

            $this->startItem('Saving <comment>'.$domain.'</comment> domain.');

            $webPath = $this->getWebsitePath($domain);
            $ignore = @file_get_contents($webPath.'/'.$this->ignoreFile) ?: null;

            $code = $this->restic()->niced()->run(array_merge(
                ['backup', '--json', '--host', $this->host(), '--tag', 'www'],
                $this->excludeArguments(Restic::websiteExcludes($webPath, $ignore)),
                [$webPath],
            ), $output, $error);

            $this->finishItem($code, $output);

            if ($code === 0) {
                $this->backedUp['www'][] = $domain;
            } else {
                $errors[] = $domain.' ('.Restic::errorMessage($error).')';
            }
        }

        if (count($errors) > 0) {
            return $this->sendError('Could not backup websites: '.implode(', ', $errors));
        }

        return $this->response()->success('<info>WWW directories have been successfully backed up.</info>');
    }

    /**
     * Get the retention policy of a type of the backups.
     *
     * @param  string  $type
     * @return array
     */
    public function getRetention(string $type): array
    {
        if ($type === 'databases') {
            return (array) $this->config('backup_keep_databases', self::KEEP_DATABASES);
        }

        return (array) $this->config('backup_keep_data', self::KEEP_DATA);
    }

    /**
     * Remove old snapshots of the given types by their retention, each database, website and the directories apart.
     * The data is removed by prune in backup:maintenance.
     *
     * @param  array  $types
     * @return void
     */
    public function removeOldBackups(array $types): void
    {
        foreach ($types as $type) {
            $code = $this->restic()->run(array_merge(
                ['forget', '--tag', $type, '--group-by', 'host,paths'],
                Restic::keepArguments($this->getRetention($type)),
            ), $output, $error);

            if ($code !== 0) {
                $this->sendError('Old backups of '.$type.' could not be removed: '.Restic::errorMessage($error));
            }
        }

        $this->response()
            ->success('<info>Old backups have been removed.</info>')
            ->writeln();
    }

    /**
     * Part of the data read by the weekly check: one twentieth by the count of weeks since 1970, so every chunk is read
     * in any 20 weeks in a row, also over the end of a year, e.g. 3/20.
     *
     * @param  \Carbon\Carbon  $date
     * @return string
     */
    public static function readDataSubset(Carbon $date): string
    {
        return (intdiv($date->getTimestamp(), 7 * 86400) % self::CHECK_PARTS + 1).'/'.self::CHECK_PARTS;
    }

    /**
     * Remove the data of the forgotten snapshots, check the repository with a part of its data and report the result
     * to the monitor, also a successful one.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function maintenance(): Response
    {
        $this->date = null;
        $this->log = [];
        $start = microtime(true);
        $subset = self::readDataSubset($this->now());

        $this->restic()->run(['unlock'], $output, $error);

        $this->response()->success('Removing unused data of old backups.')->writeln();

        if ($this->restic()->run(['prune'], $output, $error) !== 0) {
            $this->sendError('Prune of the repository failed: '.Restic::errorMessage($error));
        }

        $this->response()->success('Checking the repository and the part '.$subset.' of its data.')->writeln();

        if ($this->restic()->run(['check', '--read-data-subset='.$subset], $output, $error) !== 0) {
            $this->sendError('Check of the repository failed: '.Restic::errorMessage($error));
        }

        $this->sendNotification();
        $this->sendCheckToMonitor($subset);

        $this->log('INFO', 'Maintenance end | check '.$subset.' | '.round((microtime(true) - $start) / 60, 1).' Min.');

        if ($this->log) {
            return $this->response()->error(implode(' ', $this->log));
        }

        return $this->response()->success('Maintenance of the backups has been successfully performed.');
    }

    /**
     * Report the result of the maintenance to the monitor. When the monitor does not answer, monitor:collect sends it later.
     *
     * @param  string  $subset
     * @return void
     */
    private function sendCheckToMonitor(string $subset): void
    {
        $agent = vpsManager()->monitorAgent();

        $agent->queueBackupCheck($this->log ? 'error' : 'ok', $this->log ? implode(' ', $this->log) : null, $subset, time());

        if ($agent->getUrl() && $agent->sendBackupChecks($error) === null) {
            $this->response()->message('<comment>The monitor did not accept the check ('.$error.'), monitor:collect sends it later.</comment>')->writeln();
        }
    }

    /**
     * Test the connection to the repository.
     *
     * @return true|string true, or the error
     */
    public function testRepository(): bool|string
    {
        $code = $this->restic()->run(['cat', 'config'], $output, $error);

        return match ($code) {
            0 => true,
            Restic::EXIT_NO_REPOSITORY => 'Repository '.Restic::repository($this->config()).' does not exist, run backup:setup.',
            Restic::EXIT_WRONG_PASSWORD => 'The password of the configuration does not open the repository.',
            default => Restic::errorMessage($error),
        };
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
     * Send email with errors if notifications are enabled.
     *
     * @return void
     */
    private function sendNotification(): void
    {
        if ($this->config('email_notifications') && count($this->log) > 0) {
            $this->sendMail('Error notification', implode('<br>', array_map('htmlspecialchars', $this->log)));
        }
    }

    /**
     * Report the backed up databases, directories and websites to the monitor, which checks that every one of them
     * is backed up each day. When the monitor does not answer, monitor:collect sends them later.
     *
     * @return void
     */
    private function sendToMonitor(): void
    {
        if (! $this->backedUp) {
            return;
        }

        vpsManager()->monitorAgent()->queueBackups($this->getSnapshot(), $this->backedUp, time());

        if (vpsManager()->monitorAgent()->getUrl() && vpsManager()->monitorAgent()->sendBackups($error) === null) {
            $this->response()->message('<comment>The monitor did not accept the backups ('.$error.'), monitor:collect sends them later.</comment>')->writeln();
        }
    }

    /**
     * Name of the current backup run, the monitor groups the backups of one hour under it.
     *
     * @return string
     */
    public function getSnapshot(): string
    {
        return $this->now()->format('Y-m-d_H-00-00');
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
     * Check if there is enough disk space for a local repository, or for the cache of restic.
     *
     * @return bool
     */
    private function hasEnoughSpace(): bool
    {
        $path = $this->config('backup_repository_type') === 'local' ? $this->config('backup_local_path') : '/root';

        if (($freeSpace = $this->getFreeDiskSpace($path)) <= self::DISK_SPACE_BUFFER) {
            $this->sendError('Disk space of '.$path.' is too low for backups, '.$freeSpace.'MB is free. At least '.self::DISK_SPACE_BUFFER.'MB is required.');

            return false;
        }

        return true;
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
        $this->backedUp = [];

        $this->response()
            ->success('<info>Performing backup for '.$this->now()->format('d.m.Y H:i').'.</info>')
            ->writeln();

        $start = microtime(true);
        $types = array_values(array_filter(self::TYPES, fn ($type) => ! empty($backup[$type])));

        // A server without its databases, e.g. an application server with a separate database server
        if (in_array('databases', $types) && ! $this->backsUpDatabases()) {
            $types = array_values(array_diff($types, ['databases']));

            $this->response()->message('<comment>Databases are not backed up on this server (backup_databases).</comment>')->writeln();
        }

        if ($this->hasEnoughSpace()) {
            // Locks left by a crashed run would block the backups, only stale locks are removed
            $this->restic()->run(['unlock'], $output, $error);

            if (($test = $this->testRepository()) !== true) {
                $this->sendError('Repository is not available: '.$test);
            } else {
                if (in_array('databases', $types)) {
                    $this->backupDatabases()->writeln();
                }

                if (in_array('dirs', $types)) {
                    $this->backupDirectories()->writeln();
                }

                if (in_array('www', $types)) {
                    $this->backupWWWData()->writeln();
                }

                $this->removeOldBackups($types);
            }
        }

        $this->sendNotification();
        $this->sendToMonitor();

        $this->log(
            'INFO',
            'Backup end'.
            ' | DB:'.(in_array('databases', $types) ? 'YES' : 'NO').
            ' | WWW:'.(in_array('www', $types) ? 'YES' : 'NO').
            ' | DIRS:'.(in_array('dirs', $types) ? 'YES' : 'NO').
            ' | '.round((microtime(true) - $start) / 60, 1).' Min.',
        );

        return $this->response()->success('Full backup has been successfully performed.');
    }

    /**
     * Get the type and the name of a snapshot, e.g. ['databases', 'shop'], ['www', 'example.com'], ['dirs', '/etc/nginx /etc/php'].
     *
     * @param  array  $snapshot
     * @param  string  $wwwPath
     * @return array
     */
    public static function describeSnapshot(array $snapshot, string $wwwPath = '/var/www'): array
    {
        $tags = $snapshot['tags'] ?? [];
        $paths = $snapshot['paths'] ?? [];
        $type = current(array_intersect(self::TYPES, $tags)) ?: 'other';

        if ($type === 'databases') {
            return [$type, preg_replace('/\.sql$/', '', basename((string) ($paths[0] ?? '')))];
        }

        if ($type === 'www') {
            $relative = ltrim(substr((string) ($paths[0] ?? ''), strlen(rtrim($wwwPath, '/'))), '/');

            return [$type, explode('/', $relative)[0]];
        }

        return [$type, implode(' ', $paths)];
    }
}
