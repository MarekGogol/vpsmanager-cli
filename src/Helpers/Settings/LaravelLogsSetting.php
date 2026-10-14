<?php

namespace Gogol\VpsManagerCLI\Helpers\Settings;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;

/*
 * Monthly rotation of the logs of the Laravel applications of the hostings (storage/logs/laravel.log grew to 2.3 GB
 * on qualit). Daily logs of Laravel (laravel-2026-10-14.log) rotate themselves and are skipped. logrotate refuses
 * a directory writable by another user than root, so each directory has its block with su of its owner.
 */
class LaravelLogsSetting extends Setting
{
    const FILE = '/etc/logrotate.d/vpsmanager-laravel';

    /**
     * Logs kept, a year of months.
     */
    const ROTATE = 12;

    /**
     * A bigger log rotates before the end of the month.
     */
    const MAX_SIZE = '1G';

    /**
     * Key of the setting used by --only.
     *
     * @return string
     */
    public function key(): string
    {
        return 'laravel-logs';
    }

    /**
     * Directories of the logs of the Laravel applications: data/web and data/sub/* of every hosting.
     *
     * @param  string  $www
     * @return array
     */
    public static function directories(string $www): array
    {
        $directories = array_merge(
            glob(rtrim($www, '/').'/*/data/web/storage/logs', GLOB_ONLYDIR) ?: [],
            glob(rtrim($www, '/').'/*/data/sub/*/storage/logs', GLOB_ONLYDIR) ?: [],
        );

        sort($directories);

        return $directories;
    }

    /**
     * Content of the configuration of logrotate.
     *
     * @param  array  $directories  path => [user, group]
     * @return string
     */
    public function content(array $directories): string
    {
        $content = $this->header('Monthly rotation of the logs of the Laravel applications of the hostings, '.self::ROTATE." months kept.\n"
            .'Daily logs of Laravel (laravel-YYYY-MM-DD.log) rotate themselves and are skipped.');

        foreach ($directories as $directory => [$user, $group]) {
            $content .= $directory."/*[!0-9].log {\n"
                .'    su '.$user.' '.$group."\n"
                ."    monthly\n"
                .'    maxsize '.self::MAX_SIZE."\n"
                .'    rotate '.self::ROTATE."\n"
                ."    compress\n"
                ."    missingok\n"
                ."    notifempty\n"
                ."    copytruncate\n"
                ."}\n\n";
        }

        return rtrim($content)."\n";
    }

    /**
     * Read the directories, their owners and the sizes of the logs.
     *
     * @return array
     */
    protected function inspect(): array
    {
        if (! trim((string) shell_exec('command -v logrotate 2> /dev/null'))) {
            return ['installed' => false];
        }

        $directories = [];
        $logs = [];

        foreach (static::directories(vpsManager()->config('www_path', '/var/www')) as $directory) {
            $directories[$directory] = [
                posix_getpwuid(fileowner($directory))['name'] ?? (string) fileowner($directory),
                posix_getgrgid(filegroup($directory))['name'] ?? (string) filegroup($directory),
            ];

            foreach (glob($directory.'/*[!0-9].log') ?: [] as $log) {
                $logs[$log] = (int) filesize($log);
            }
        }

        arsort($logs);

        $state = [
            'installed' => true,
            'directories' => $directories,
            'logs' => $logs,
            'content' => $this->content($directories),
            'warnings' => [],
        ];

        $state['written'] = ! $directories || is_file(self::FILE) && file_get_contents(self::FILE) === $state['content'];

        // copytruncate copies the log before it truncates it
        $free = (int) disk_free_space(vpsManager()->config('www_path', '/var/www'));

        foreach ($logs as $log => $size) {
            if ($size > 512 * SystemInfo::MB && $size > $free / 2) {
                $state['warnings'][] = $log.' has '.static::size($size).', its first rotation needs as much free disk ('.static::size($free).' free).';
            }
        }

        return $state;
    }

    /**
     * Current value of the setting for the table.
     *
     * @return string
     */
    public function current(): string
    {
        $state = $this->state();

        if (! $state['installed']) {
            return 'no logrotate';
        }

        if (! $state['logs']) {
            return 'no logs';
        }

        return count($state['logs']).' logs, biggest '.static::size(reset($state['logs']));
    }

    /**
     * Recommended value of the setting for the table.
     *
     * @return string
     */
    public function recommended(): string
    {
        return $this->state()['installed'] ? 'monthly, '.self::ROTATE.' kept' : '-';
    }

    /**
     * Changes which would be made.
     *
     * @return array
     */
    public function plan(): array
    {
        $state = $this->state();

        return ! $state['installed'] || $state['written'] ? [] : ['write '.self::FILE.' ('.count($state['directories']).' directories)'];
    }

    /**
     * Write the configuration and check it by a dry run of logrotate.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Closure  $confirm
     * @return bool
     */
    public function apply(OutputInterface $output, Closure $confirm): bool
    {
        $previous = [self::FILE => is_file(self::FILE) ? file_get_contents(self::FILE) : null];

        if (! $this->write(self::FILE, $this->state()['content'], $output)) {
            return false;
        }

        // -d changes nothing, it only reads the configuration
        $this->run('logrotate -d '.escapeshellarg(self::FILE), $lines);
        $errors = preg_grep('/^error:/i', $lines);

        if ($errors) {
            $output->writeln('<error>logrotate: '.implode(' ', $errors).'</error>');

            return $this->restore($previous, $output, 'The configuration of logrotate has been restored.');
        }

        $output->writeln('The logs rotate monthly with the daily run of logrotate.');

        return true;
    }
}
