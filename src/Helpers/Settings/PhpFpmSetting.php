<?php

namespace Gogol\VpsManagerCLI\Helpers\Settings;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;

/*
 * Pools of PHP-FPM: the default pool www of a version, which no NGINX host uses, is turned off, every pool restarts
 * its processes after pm.max_requests requests (the memory of a process grows with the requests), small pools
 * (pm.max_children up to 5) start their processes on demand and have none while the website is idle.
 */
class PhpFpmSetting extends Setting
{
    const MAX_REQUESTS = 500;

    /**
     * Pools with this many processes at most run on demand.
     */
    const SMALL_POOL = 5;

    const IDLE_TIMEOUT = '10s';

    /**
     * Suffix of a turned off pool, php-fpm reads only *.conf.
     */
    const DISABLED = '.vpsmanager-disabled';

    /**
     * Key of the setting used by --only.
     *
     * @return string
     */
    public function key(): string
    {
        return 'php-fpm';
    }

    /**
     * Value of a directive of a pool, null when it is missing or commented out.
     *
     * @param  string  $content
     * @param  string  $directive
     * @return string|null
     */
    public static function directive(string $content, string $directive): ?string
    {
        return preg_match('/^\s*'.preg_quote($directive, '/').'\s*=\s*(.*?)\s*$/m', $content, $match) ? $match[1] : null;
    }

    /**
     * Set a directive of a pool: its line, its commented out line, or a new line after the line of $after.
     *
     * @param  string  $content
     * @param  string  $directive
     * @param  string  $value
     * @param  string  $after
     * @return string
     */
    protected static function setDirective(string $content, string $directive, string $value, string $after): string
    {
        $line = $directive.' = '.$value;
        $pattern = '/^\s*;?\s*'.preg_quote($directive, '/').'\s*=.*$/m';

        if (preg_match($pattern, $content)) {
            return preg_replace($pattern, $line, $content, 1);
        }

        return preg_replace('/^(\s*'.preg_quote($after, '/').'\s*=.*)$/m', '$1'."\n".$line, $content, 1);
    }

    /**
     * Content of a pool with pm.max_requests and a small pool on demand.
     *
     * @param  string  $content
     * @return array [content, list of the changes]
     */
    public static function tune(string $content): array
    {
        $changes = [];

        if (static::directive($content, 'pm.max_requests') === null) {
            $content = static::setDirective($content, 'pm.max_requests', (string) self::MAX_REQUESTS, 'pm.max_children');
            $changes[] = 'pm.max_requests '.self::MAX_REQUESTS;
        }

        $children = (int) static::directive($content, 'pm.max_children');

        if (static::directive($content, 'pm') === 'dynamic' && $children > 0 && $children <= self::SMALL_POOL) {
            $content = static::setDirective($content, 'pm', 'ondemand', 'pm');
            $content = static::setDirective($content, 'pm.process_idle_timeout', self::IDLE_TIMEOUT, 'pm.max_children');
            $changes[] = 'ondemand';
        }

        return [$content, $changes];
    }

    /**
     * Pools of the versions of PHP.
     *
     * @return array path => [version, name, listen, content]
     */
    protected function pools(): array
    {
        $pools = [];

        foreach (glob('/etc/php/*/fpm/pool.d/*.conf') ?: [] as $path) {
            $content = (string) file_get_contents($path);

            $pools[$path] = [
                'version' => basename(dirname($path, 3)),
                'name' => preg_match('/^\s*\[([^\]]+)\]/m', $content, $match) ? $match[1] : basename($path, '.conf'),
                'listen' => (string) static::directive($content, 'listen'),
                'content' => $content,
            ];
        }

        return $pools;
    }

    /**
     * Configuration of NGINX with the included files.
     *
     * @return string
     */
    protected function nginx(): string
    {
        exec('grep -Rh "fastcgi_pass\|php-fpm\|\.sock" /etc/nginx 2> /dev/null', $lines);

        return implode("\n", $lines);
    }

    /**
     * Average memory of a process of PHP-FPM by the version.
     *
     * @return array version => bytes
     */
    protected function memory(): array
    {
        exec('ps -eo rss=,comm= 2> /dev/null', $lines);

        $sizes = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*(\d+)\s+php-fpm([\d.]+)$/', $line, $match)) {
                $sizes[$match[2]][] = (int) $match[1] * 1024;
            }
        }

        return array_map(fn ($values) => (int) (array_sum($values) / count($values)), $sizes);
    }

    /**
     * Read the pools, the sockets used by NGINX and the memory of the processes.
     *
     * @return array
     */
    protected function inspect(): array
    {
        $pools = $this->pools();

        if (! $pools) {
            return ['installed' => false];
        }

        $nginx = $this->nginx();
        $memory = $this->memory();
        $versions = array_count_values(array_column($pools, 'version'));
        $changes = [];
        $disable = [];
        $load = 0;

        foreach ($pools as $path => $pool) {
            // The generic socket php-fpm.sock leads to the pool www of the default version
            $used = $pool['listen'] === '' || str_contains($nginx, $pool['listen']) || ($pool['name'] === 'www' && str_contains($nginx, 'php-fpm.sock'));

            if ($pool['name'] === 'www' && ! $used && $versions[$pool['version']] > 1) {
                $disable[] = $path;

                continue;
            }

            [$content, $poolChanges] = static::tune($pool['content']);

            if ($poolChanges) {
                $changes[$path] = ['content' => $content, 'changes' => $poolChanges, 'version' => $pool['version']];
            }

            $children = (int) static::directive($content, 'pm.max_children');
            $load += $children * ($memory[$pool['version']] ?? 64 * SystemInfo::MB);
        }

        $ram = SystemInfo::ram();
        $warnings = [];

        if ($ram && $load > $ram * 0.6) {
            $warnings[] = 'At full load the pools may take '.static::size($load).' (pm.max_children × the average process) of '.static::size($ram).' of RAM, lower pm.max_children of the busy pools.';
        }

        return [
            'installed' => true,
            'pools' => count($pools),
            'disable' => $disable,
            'changes' => $changes,
            'load' => $load,
            'warnings' => $warnings,
        ];
    }

    /**
     * Current value of the setting for the table.
     *
     * @return string
     */
    public function current(): string
    {
        $state = $this->state();

        return $state['installed'] ? $state['pools'].' pools, '.static::size($state['load']).' at full load' : 'not installed';
    }

    /**
     * Recommended value of the setting for the table.
     *
     * @return string
     */
    public function recommended(): string
    {
        return $this->state()['installed'] ? 'max_requests '.self::MAX_REQUESTS.', small on demand' : '-';
    }

    /**
     * Changes which would be made.
     *
     * @return array
     */
    public function plan(): array
    {
        $state = $this->state();

        if (! $state['installed']) {
            return [];
        }

        $plan = $state['disable'] ? ['turn off unused www: '.implode(', ', array_map(fn ($path) => basename(dirname($path, 3)), $state['disable']))] : [];

        // Pools grouped by their changes, e.g. "pm.max_requests 500, ondemand: kampo.sk (7.4), haus.sk (8.3)"
        $groups = [];

        foreach ($state['changes'] as $path => $change) {
            $groups[implode(', ', $change['changes'])][] = basename($path, '.conf').' ('.$change['version'].')';
        }

        foreach ($groups as $changes => $pools) {
            $plan[] = $changes.': '.implode(', ', $pools);
        }

        if ($plan) {
            $plan[] = 'reload PHP-FPM';
        }

        return $plan;
    }

    /**
     * Change the pools, test the configuration and reload PHP-FPM gracefully.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Closure  $confirm
     * @return bool
     */
    public function apply(OutputInterface $output, Closure $confirm): bool
    {
        $state = $this->state();
        $previous = [];
        $versions = [];

        foreach ($state['changes'] as $path => $change) {
            $previous[$path] = file_get_contents($path);
            $versions[$change['version']] = true;

            if (! $this->write($path, $change['content'], $output)) {
                return $this->restore($previous, $output, 'The pools have been restored.');
            }
        }

        foreach ($state['disable'] as $path) {
            $previous[$path] = file_get_contents($path);
            $versions[basename(dirname($path, 3))] = true;
            rename($path, $path.self::DISABLED);
            $output->writeln('Turned off <info>'.$path.'</info> (renamed to '.basename($path).self::DISABLED.').');
        }

        foreach (array_keys($versions) as $version) {
            if (! $this->run('php-fpm'.$version.' -t', $lines)) {
                $output->writeln('<error>php-fpm'.$version.' -t failed: '.implode(' ', $lines).'</error>');

                foreach ($state['disable'] as $path) {
                    @rename($path.self::DISABLED, $path);
                }

                return $this->restore(array_diff_key($previous, array_flip($state['disable'])), $output, 'The pools have been restored.');
            }
        }

        foreach (array_keys($versions) as $version) {
            if (! $this->reload($version, $output)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reload PHP-FPM gracefully, the running requests finish: by systemd, or by USR2 to its master without it.
     *
     * @param  string  $version
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return bool
     */
    protected function reload(string $version, OutputInterface $output): bool
    {
        $master = $this->master($version);

        if (is_dir('/run/systemd/system')) {
            $reloaded = $this->run('systemctl reload php'.$version.'-fpm', $lines);
        } else {
            $reloaded = $master && posix_kill($master, SIGUSR2);
            $lines = [];
        }

        // The master replaces its processes, the turned off pools have none
        for ($i = 0; $i < 20; $i++) {
            usleep(500000);
            $pools = $this->runningPools($version);

            if ($pools !== null && ! array_intersect($pools, $this->disabledNames($version))) {
                break;
            }
        }

        if (! $reloaded || $pools === null || array_intersect($pools, $this->disabledNames($version))) {
            $output->writeln('<error>PHP-FPM '.$version.' has not been reloaded: '.implode(' ', $lines).'</error>');

            return false;
        }

        $output->writeln('PHP-FPM <info>'.$version.'</info> has been reloaded'.($pools ? ', running pools: '.implode(', ', array_unique($pools)) : ', the pools on demand have no idle processes').'.');

        return true;
    }

    /**
     * Pid of the master of PHP-FPM of the version from its pid file.
     *
     * @param  string  $version
     * @return int|null
     */
    protected function master(string $version): ?int
    {
        $config = (string) @file_get_contents('/etc/php/'.$version.'/fpm/php-fpm.conf');
        $file = static::directive($config, 'pid') ?? '/run/php/php'.$version.'-fpm.pid';
        $pid = (int) trim((string) @file_get_contents($file));

        if ($pid > 0 && posix_kill($pid, 0)) {
            return $pid;
        }

        // A master without a pid file, e.g. run by supervisor
        $pid = (int) exec('pgrep -o -f '.escapeshellarg('php-fpm: master process \\(/etc/php/'.$version.'/'));

        return $pid > 0 ? $pid : null;
    }

    /**
     * Names of the pools with running processes of the master of the version.
     *
     * @param  string  $version
     * @return array|null null when the master does not run
     */
    protected function runningPools(string $version): ?array
    {
        if (! $master = $this->master($version)) {
            return null;
        }

        exec('ps -o args= --ppid '.$master.' 2> /dev/null', $lines);

        return array_values(array_filter(array_map(fn ($line) => preg_match('/pool (\S+)/', $line, $match) ? $match[1] : null, $lines)));
    }

    /**
     * Names of the pools turned off by this run for the version.
     *
     * @param  string  $version
     * @return array
     */
    protected function disabledNames(string $version): array
    {
        $names = [];

        foreach ($this->state()['disable'] as $path) {
            if (basename(dirname($path, 3)) === $version && preg_match('/^\s*\[([^\]]+)\]/m', (string) @file_get_contents($path.self::DISABLED), $match)) {
                $names[] = $match[1];
            }
        }

        return $names;
    }
}
