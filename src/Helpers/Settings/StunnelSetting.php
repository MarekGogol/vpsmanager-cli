<?php

namespace Gogol\VpsManagerCLI\Helpers\Settings;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;

/*
 * Tunnels of stunnel in the client mode, e.g. a CIFS mount of the backup storage through //localhost. A tunnel
 * accepting on all addresses (accept = 445) is open to the internet: SMB scanners connect through it to the
 * remote storage and every connection writes several lines into the log. Client tunnels accept on 127.0.0.1 only,
 * the log keeps the warnings and the errors.
 */
class StunnelSetting extends Setting
{
    const DIRECTORY = '/etc/stunnel';

    /**
     * Level of the log: warnings and errors, without a notice of every connection.
     */
    const DEBUG = 'warning';

    /**
     * Key of the setting used by --only.
     *
     * @return string
     */
    public function key(): string
    {
        return 'stunnel';
    }

    /**
     * Determine if the address of accept is the loopback only.
     *
     * @param  string  $accept  e.g. 445, 0.0.0.0:445, 127.0.0.1:445, localhost:445, [::1]:445
     * @return bool
     */
    public static function isLocal(string $accept): bool
    {
        if (! preg_match('/^(.*):\d+$/', $accept, $match)) {
            return false;
        }

        $host = trim($match[1], '[]');

        return $host === 'localhost' || $host === '::1' || str_starts_with($host, '127.');
    }

    /**
     * Pid file of a config file, the init script of Debian stops stunnel by it.
     *
     * @param  string  $path
     * @return string
     */
    public static function pidPath(string $path): string
    {
        return '/run/stunnel4/'.basename($path, '.conf').'.pid';
    }

    /**
     * Content of a config file with the client tunnels on 127.0.0.1, the level of the log and a pid file.
     *
     * @param  string  $content
     * @param  string  $pid  pid file added when the file has none
     * @return array [content, list of the changes]
     */
    public static function rewrite(string $content, string $pid = '/run/stunnel4/stunnel.pid'): array
    {
        $lines = explode("\n", $content);
        $sections = [];
        $section = null;
        $debug = null;
        $hasPid = false;
        $firstSection = null;

        // Global options are before the first [service], the options of a service follow its line
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);

            if (preg_match('/^\[([^\]]+)\]/', $trimmed, $match)) {
                $section = $match[1];
                $sections[$section] = ['client' => false, 'accept' => null];
                $firstSection ??= $i;

                continue;
            }

            if (! preg_match('/^(\w+)\s*=\s*(.*?)\s*$/', $trimmed, $match) || str_starts_with($trimmed, ';') || str_starts_with($trimmed, '#')) {
                continue;
            }

            $option = strtolower($match[1]);

            if ($section === null && $option === 'debug') {
                $debug = $i;
            } elseif ($section === null && $option === 'pid') {
                $hasPid = true;
            } elseif ($section !== null && $option === 'client') {
                $sections[$section]['client'] = in_array(strtolower($match[2]), ['yes', '1', 'true'], true);
            } elseif ($section !== null && $option === 'accept') {
                $sections[$section]['accept'] = ['line' => $i, 'value' => $match[2]];
            }
        }

        $changes = [];

        foreach ($sections as $name => $service) {
            if (! $service['client'] || ! $service['accept'] || static::isLocal($service['accept']['value'])) {
                continue;
            }

            $port = preg_match('/(\d+)$/', $service['accept']['value'], $match) ? $match[1] : $service['accept']['value'];
            $lines[$service['accept']['line']] = 'accept = 127.0.0.1:'.$port;
            $changes[] = $name.' accept '.$service['accept']['value'].' -> 127.0.0.1:'.$port;
        }

        $level = $debug === null ? null : strtolower(trim(explode('=', $lines[$debug], 2)[1]));
        $global = [];

        if ($level !== self::DEBUG && $level !== '4') {
            if ($debug !== null) {
                $lines[$debug] = 'debug = '.self::DEBUG;
            } else {
                $global[] = 'debug = '.self::DEBUG;
            }

            $changes[] = 'debug '.($level ?? 'notice').' -> '.self::DEBUG;
        }

        // Without a pid file the init script cannot stop stunnel and a restart starts a second one
        if (! $hasPid) {
            $global[] = 'pid = '.$pid;
            $changes[] = 'pid file';
        }

        if ($global) {
            array_splice($lines, $firstSection ?? 0, 0, array_merge($global, ['']));
        }

        return [implode("\n", $lines), $changes];
    }

    /**
     * Read the config files of stunnel.
     *
     * @return array
     */
    protected function inspect(): array
    {
        $files = [];

        foreach (glob(self::DIRECTORY.'/*.conf') ?: [] as $path) {
            $content = (string) file_get_contents($path);
            [$rewritten, $changes] = static::rewrite($content, static::pidPath($path));

            if ($changes) {
                $files[$path] = ['content' => $rewritten, 'changes' => $changes];
            }
        }

        $public = [];

        foreach (glob(self::DIRECTORY.'/*.conf') ?: [] as $path) {
            foreach (explode("\n", (string) file_get_contents($path)) as $line) {
                if (preg_match('/^\s*accept\s*=\s*(\S+)/', $line, $match) && ! static::isLocal($match[1])) {
                    $public[] = $match[1];
                }
            }
        }

        return [
            'installed' => is_dir(self::DIRECTORY) && (bool) glob(self::DIRECTORY.'/*.conf'),
            'public' => $public,
            'files' => $files,
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

        if (! $state['installed']) {
            return 'not installed';
        }

        return $state['public'] ? 'public '.implode(', ', array_unique($state['public'])) : 'local';
    }

    /**
     * Recommended value of the setting for the table.
     *
     * @return string
     */
    public function recommended(): string
    {
        return $this->state()['installed'] ? 'clients local, log warnings' : '-';
    }

    /**
     * Changes which would be made.
     *
     * @return array
     */
    public function plan(): array
    {
        $files = $this->state()['files'];

        if (! $files) {
            return [];
        }

        $plan = array_merge(...array_values(array_map(fn ($file) => $file['changes'], $files)));
        $plan[] = 'restart stunnel4';

        return $plan;
    }

    /**
     * Notes about the tunnels which are not changed.
     *
     * @return array
     */
    public function warnings(): array
    {
        $state = $this->state();
        $changed = [];

        foreach ($state['files'] as $file) {
            foreach ($file['changes'] as $change) {
                if (preg_match('/ accept (\S+) ->/', $change, $match)) {
                    $changed[] = $match[1];
                }
            }
        }

        $kept = array_diff($state['public'] ?? [], $changed);

        return $kept ? ['Tunnels of the server mode accept on '.implode(', ', $kept).', they are not changed.'] : [];
    }

    /**
     * Pids of stunnel listening on any port.
     *
     * @return array
     */
    protected function listeningPids(): array
    {
        exec('ss -ltnpH 2> /dev/null', $listening);

        preg_match_all('/"stunnel4?",pid=(\d+)/', implode("\n", $listening), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Write the config files and restart stunnel, not during a backup which uses its tunnel.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Closure  $confirm
     * @return bool
     */
    public function apply(OutputInterface $output, Closure $confirm): bool
    {
        if (SwapRefresh::isBackupRunning()) {
            $output->writeln('<error>backup:run is running, stunnel is not restarted now.</error>');

            return false;
        }

        // Processes of stunnel started without a pid file are stopped by their ports
        $pids = $this->listeningPids();
        $previous = [];

        foreach ($this->state()['files'] as $path => $file) {
            $previous[$path] = file_get_contents($path);

            if (! $this->write($path, $file['content'], $output)) {
                return $this->restore($previous, $output, 'The files of stunnel have been restored.');
            }
        }

        if ($pids) {
            exec('kill '.implode(' ', $pids).' 2> /dev/null');

            for ($i = 0; $i < 10 && $this->listeningPids(); $i++) {
                usleep(500000);
            }
        }

        @mkdir('/run/stunnel4', 0755);

        if (! $this->run('(systemctl restart stunnel4 || service stunnel4 restart)', $lines)) {
            $output->writeln('<error>stunnel could not be restarted: '.implode(' ', $lines).'</error>');
            $this->restore($previous, $output, 'The files of stunnel have been restored.');
            $this->run('(systemctl restart stunnel4 || service stunnel4 restart)');

            return false;
        }

        // The ports of the changed tunnels must not listen on a public address any more
        $ports = [];

        foreach ($this->state()['files'] as $file) {
            foreach ($file['changes'] as $change) {
                if (preg_match('/-> 127\.0\.0\.1:(\d+)$/', $change, $match)) {
                    $ports[] = $match[1];
                }
            }
        }

        sleep(1);
        exec('ss -ltnpH 2> /dev/null', $listening);

        $public = [];

        foreach ($listening as $line) {
            $local = preg_split('/\s+/', trim($line))[3] ?? '';

            if (str_contains($line, 'stunnel') && in_array(substr(strrchr($local, ':'), 1), $ports, true) && ! static::isLocal($local)) {
                $public[] = $local;
            }
        }

        if ($public) {
            $output->writeln('<error>stunnel still listens on '.implode(', ', $public).'.</error>');

            return false;
        }

        $output->writeln('stunnel listens on <info>127.0.0.1</info> only, its log keeps the warnings.');

        return true;
    }
}
