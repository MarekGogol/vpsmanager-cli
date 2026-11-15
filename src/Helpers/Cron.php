<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

/*
 * The one cron file of VPS Manager. It is generated whole from the configuration, so backup:setup and monitor:install
 * can both rewrite it without losing the jobs of each other.
 */
class Cron extends Application
{
    /**
     * Cron file of VPS Manager, without a dot in its name, cron ignores such files.
     *
     * @var string
     */
    const PATH = '/etc/cron.d/vpsmanager';

    /**
     * Hour of the daily backup of everything.
     *
     * @var int
     */
    const BACKUP_HOUR = 4;

    /**
     * Default interval of the backups of the databases in hours.
     *
     * @var int
     */
    const DATABASES_INTERVAL = 6;

    /**
     * Intervals of the backups of the databases in hours, which divide the day evenly. 0 backs them up only daily.
     *
     * @var array
     */
    const DATABASES_INTERVALS = [0, 1, 2, 3, 4, 6, 8, 12];

    /**
     * Commands of the old jobs in the crontab of root, replaced by this file.
     *
     * @var array
     */
    const ROOT_CRONTAB_COMMANDS = ['vpsmanager backup:run', 'vpsmanager backup:maintenance'];

    /**
     * Get the command of VPS Manager with the current PHP binary.
     *
     * @param  string  $arguments
     * @return string
     */
    public function command(string $arguments): string
    {
        $path = realpath(vpsManagerPath().'/../vpsmanager') ?: vpsManagerPath().'/../vpsmanager';

        return escapeshellarg(PHP_BINARY).' '.escapeshellarg($path).' '.$arguments;
    }

    /**
     * Determine if monitor:collect runs, before this file it had its own cron file.
     *
     * @return bool
     */
    public function isMonitorEnabled(): bool
    {
        return (bool) $this->config('monitor_collect', file_exists(MonitorAgent::CRON));
    }

    /**
     * Determine if the backups run from cron.
     *
     * @return bool
     */
    public function areBackupsEnabled(): bool
    {
        return vpsManager()->backup()->isConfigured() && $this->config('crontab_add', true);
    }

    /**
     * Get the jobs of the backups.
     *
     * @param  string  $command  command of VPS Manager without arguments
     * @param  int  $interval  hours between the backups of the databases, 0 for none
     * @return array
     */
    public static function backupJobs(string $command, int $interval): array
    {
        $jobs = ['0 '.self::BACKUP_HOUR.' * * * root '.$command.' backup:run > /dev/null 2>&1'];

        if (in_array($interval, self::DATABASES_INTERVALS) && $interval > 0) {
            // The hour of the daily backup is left out, it backs up the databases too
            $hours = array_values(array_diff(range(0, 23, $interval), [self::BACKUP_HOUR]));

            $jobs[] = '0 '.implode(',', $hours).' * * * root '.$command.' backup:run --databases > /dev/null 2>&1';
        }

        $jobs[] = '30 5 * * 0 root '.$command.' backup:maintenance > /dev/null 2>&1';

        return $jobs;
    }

    /**
     * Get the contents of the cron file, null when there is no job.
     *
     * @return string|null
     */
    public function getContent(): ?string
    {
        $sections = [];

        if ($this->isMonitorEnabled()) {
            $sections[] = "# Sends the metrics of the server, the requests of its hosts and its bans to the monitor every 5 minutes and\n"
                ."# bans the addresses shared by the monitor. The minute is derived from the hostname (monitor:install).\n"
                .vpsManager()->monitorAgent()->getCronJob($this->command('monitor:collect --spread'));
        }

        if ($this->areBackupsEnabled()) {
            $interval = (int) $this->config('backup_databases_interval', self::DATABASES_INTERVAL);

            $sections[] = "# Backups into the restic repository: everything daily, the databases more often, the maintenance on Sundays\n"
                ."# (backup:setup).\n"
                .implode("\n", self::backupJobs(rtrim($this->command('')), $interval));
        }

        if (! $sections) {
            return null;
        }

        return "# Jobs of VPS Manager, managed by backup:setup and monitor:install, changes are overwritten.\n\n"
            .implode("\n\n", $sections)."\n";
    }

    /**
     * Write the cron file when it differs, remove it without jobs, and remove the old cron files.
     *
     * @param  bool  $dryRun
     * @return array changed files, e.g. ['Wrote /etc/cron.d/vpsmanager', 'Removed /etc/cron.d/vpsmanager-monitor-collect']
     */
    public function write(bool $dryRun = false): array
    {
        $changes = [];

        // The state of the old cron file of the monitor is kept in the configuration
        if (! $dryRun && $this->config('monitor_collect') === null && file_exists(MonitorAgent::CRON)) {
            vpsManager()->saveConfig(['monitor_collect' => true] + vpsManager()->config());
            vpsManager()->bootConfig(true);
        }

        $content = $this->getContent();

        if ($content === null) {
            if (file_exists(self::PATH)) {
                $changes[] = ($dryRun ? 'Would remove ' : 'Removed ').self::PATH;

                if (! $dryRun) {
                    @unlink(self::PATH);
                }
            }
        } elseif (! file_exists(self::PATH) || file_get_contents(self::PATH) !== $content) {
            $changes[] = ($dryRun ? 'Would write ' : 'Wrote ').self::PATH;

            if (! $dryRun) {
                file_put_contents(self::PATH, $content);
                chmod(self::PATH, 0644);
            }
        }

        if (file_exists(MonitorAgent::CRON)) {
            $changes[] = ($dryRun ? 'Would remove ' : 'Removed ').MonitorAgent::CRON.' (replaced by '.self::PATH.')';

            if (! $dryRun) {
                @unlink(MonitorAgent::CRON);
            }
        }

        foreach ($this->removeFromRootCrontab($dryRun) as $line) {
            $changes[] = ($dryRun ? 'Would remove' : 'Removed').' from the crontab of root: '.$line;
        }

        return $changes;
    }

    /**
     * Remove the old jobs of the backups from the crontab of root.
     *
     * @param  bool  $dryRun
     * @return array removed lines
     */
    public function removeFromRootCrontab(bool $dryRun = false): array
    {
        exec('crontab -l -u root 2>/dev/null', $lines, $code);

        if ($code !== 0) {
            return [];
        }

        [$kept, $removed] = self::filterRootCrontab($lines);

        if (! $removed || $dryRun) {
            return $removed;
        }

        $process = proc_open(['crontab', '-u', 'root', '-'], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

        if (! is_resource($process)) {
            return [];
        }

        fwrite($pipes[0], $kept ? implode("\n", $kept)."\n" : '');
        fclose($pipes[0]);

        return proc_close($process) === 0 ? $removed : [];
    }

    /**
     * Split the lines of the crontab of root into the kept ones and the old jobs of VPS Manager.
     *
     * @param  array  $lines
     * @return array [kept, removed]
     */
    public static function filterRootCrontab(array $lines): array
    {
        $kept = $removed = [];

        foreach ($lines as $line) {
            $isJob = ! str_starts_with(ltrim($line), '#');

            foreach (self::ROOT_CRONTAB_COMMANDS as $command) {
                if ($isJob && str_contains($line, $command)) {
                    $removed[] = $line;

                    continue 2;
                }
            }

            $kept[] = $line;
        }

        return [$kept, $removed];
    }
}
