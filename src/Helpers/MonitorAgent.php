<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;
use Symfony\Component\Console\Output\OutputInterface;

/*
 * Agent of the monitor (monitor:collect every 5 minutes): metrics of the server, requests of its hosts by windows
 * of 5 minutes and the shared bans, all in one call of the monitor. It replaces the bash agent of the monitor
 * (/etc/server_monitor) and the former monitor:sync.
 */
class MonitorAgent extends Application
{
    /**
     * Version of the agent, sent to the monitor.
     */
    const VERSION = '2.0.0';

    /**
     * Cron of monitor:collect.
     */
    const CRON = '/etc/cron.d/vpsmanager-monitor-collect';

    /**
     * State between the runs: positions in the logs, unsent windows, reported and shared bans.
     */
    const STATE = '/var/lib/vpsmanager/monitor-collect.json';

    /**
     * Counters of CPU and network of the previous run of metrics.sh.
     */
    const METRICS_STATE = '/var/lib/vpsmanager/monitor-metrics.state';

    /**
     * Lock of a running monitor:collect, a slow monitor never runs it twice.
     */
    const LOCK = '/var/lib/vpsmanager/monitor-collect.lock';

    /**
     * Old bash agent of the monitor: its directory and its user with the crontab.
     */
    const LEGACY_DIRECTORY = '/etc/server_monitor';

    const LEGACY_USER = 'server_monitor';

    /**
     * Seconds of a window of the requests.
     */
    const WINDOW = 300;

    /**
     * Seconds after the end of a window before it is sent: NGINX writes the log every 5 seconds.
     */
    const FLUSH_DELAY = 15;

    /**
     * Seconds of the windows kept while the monitor does not answer.
     */
    const KEEP_WINDOWS = 3600;

    /**
     * Endpoints of a host in a window sent to the monitor: the most requested ones and the ones with the longest
     * total duration, the rest is one endpoint OTHER_ENDPOINTS.
     */
    const TOP_ENDPOINTS = 20;

    const OTHER_ENDPOINTS = '(ostatné)';

    /**
     * Seconds to wait for the monitor.
     */
    const TIMEOUT = 20;

    /**
     * Url of the monitor (e.g. https://monitor.marekgogol.sk), monitor_url of the configuration.
     *
     * @return string|null
     */
    public function getUrl(): ?string
    {
        return $this->config('monitor_url');
    }

    /**
     * Token of this server in the monitor, monitor_token of the configuration.
     *
     * @return string|null
     */
    public function getToken(): ?string
    {
        return $this->config('monitor_token');
    }

    /**
     * Url, id and token of the old bash agent (/etc/server_monitor/monitor.sh posts to {url}/monitor/{id}/{token}/collect),
     * offered by monitor:install when it replaces the agent.
     *
     * @return array|null ['url', 'id', 'token']
     */
    public function getLegacyCredentials(): ?array
    {
        $script = is_readable(Monitor::AGENT_SCRIPT) ? file_get_contents(Monitor::AGENT_SCRIPT) : '';

        if (! preg_match('#(https?://[^\s"\']+?)/monitor/(\d+)/([A-Za-z0-9]+)/collect#', $script, $matches)) {
            return null;
        }

        return ['url' => $matches[1], 'id' => (int) $matches[2], 'token' => $matches[3]];
    }

    /**
     * Call the API of the monitor for agents (/api/agent/...) with the token of this server.
     *
     * @param  string  $method
     * @param  string  $path  e.g. collect
     * @param  array|null  $body
     * @param  string|null  $error
     * @return array|null
     */
    public function request(string $method, string $path, ?array $body = null, ?string &$error = null): ?array
    {
        if (! ($url = $this->getUrl()) || ! ($token = $this->getToken())) {
            $error = 'the monitor is not configured, run monitor:install';

            return null;
        }

        return vpsManager()->monitor()->requestMonitor($method, rtrim($url, '/').'/api/agent/'.$path, $body, $error, $token, self::TIMEOUT);
    }

    /**
     * State between the runs.
     *
     * @return array
     */
    public function loadState(): array
    {
        $state = is_readable(self::STATE) ? json_decode((string) file_get_contents(self::STATE), true) : null;

        return is_array($state) ? $state : [];
    }

    /**
     * Save the state between the runs.
     *
     * @param  array  $state
     * @return void
     */
    public function saveState(array $state): void
    {
        if (! is_dir(dirname(self::STATE))) {
            mkdir(dirname(self::STATE), 0700, true);
        }

        file_put_contents(self::STATE, json_encode($state, JSON_UNESCAPED_SLASHES));
        chmod(self::STATE, 0600);
    }

    /**
     * Metrics of the server from metrics.sh (the logic of the old bash agent).
     *
     * @return array key => value, empty when the script fails
     */
    public function collectMetrics(): array
    {
        exec('bash '.escapeshellarg(__DIR__.'/../Resources/monitor/metrics.sh').' '.escapeshellarg(self::METRICS_STATE).' 2> /dev/null', $lines, $return_var);

        $metrics = [];

        foreach ($lines as $line) {
            if (preg_match('/^([a-z_]+)=(.*)$/', $line, $matches)) {
                $metrics[$matches[1]] = $matches[2];
            }
        }

        return $return_var === 0 ? $metrics : [];
    }

    /**
     * Count the new lines of the access log and of the log of the blocked requests into windows of 5 minutes and
     * return the closed windows which have not been sent. The first run starts at the end of the logs, its window
     * is not complete and is skipped.
     *
     * @param  array  $state  updated: positions, windows, sent_until
     * @param  int  $now
     * @return array list of ['start', 'seconds', 'host', 'requests', 'blocked', 'errors', 'duration_ms', 'histogram', 'endpoints']
     */
    public function collectHosts(array &$state, int $now): array
    {
        $stats = vpsManager()->requestStats();
        $logs = [Monitor::LOG => 'access', RequestStats::SCANNERS_LOG => 'scanners'];

        if (! isset($state['sent_until'])) {
            $state['sent_until'] = intdiv($now, self::WINDOW) * self::WINDOW + self::WINDOW;
        }

        $hosts = null;

        foreach ($logs as $path => $key) {
            if (! file_exists($path)) {
                continue;
            }

            $position = $state['positions'][$key] ?? $stats->getEndPosition($path);

            foreach ($stats->readNewLines($path, $position) as $line) {
                $request = $key === 'access' ? $stats->parseRequest($line) : $stats->parseBlocked($line);
                $start = $request ? intdiv($request['time'], self::WINDOW) * self::WINDOW : null;

                // Windows sent already, a line written late is lost
                if ($start === null || $start < $state['sent_until']) {
                    continue;
                }

                $host = $stats->normalizeHost($request['host'], $hosts ??= $stats->getHosts());
                $window = &$state['windows'][$start][$host];
                $window ??= [0, 0, 0, 0];

                // Histogram and endpoints, also of a window counted by a version without them
                $window[4] ??= array_fill(0, count(RequestStats::BANDS) + 1, 0);
                $window[5] ??= [];

                if ($key === 'access') {
                    $duration = (int) round($request['duration'] * 1000);
                    $error = $request['status'] >= 500 ? 1 : 0;

                    $window[0]++;
                    $window[2] += $error;
                    $window[3] += $duration;
                    $window[4][$stats->durationBand($duration)]++;

                    // Endpoint: requests, duration, the slowest request, errors, url and time of the slowest request
                    $endpoint = &$window[5][$stats->normalizeEndpoint($request['method'], $request['url'])];
                    $endpoint ??= [0, 0, -1, 0, '', 0];
                    $endpoint[0]++;
                    $endpoint[1] += $duration;
                    $endpoint[3] += $error;

                    if ($duration > $endpoint[2]) {
                        $endpoint[2] = $duration;
                        $endpoint[4] = mb_substr($request['url'], 0, 500);
                        $endpoint[5] = $request['time'];
                    }

                    unset($endpoint);
                } else {
                    $window[1]++;
                }

                unset($window);
            }

            $state['positions'][$key] = $position;
        }

        $rows = [];

        foreach ($state['windows'] ?? [] as $start => $hostsOfWindow) {
            // Windows of a monitor which does not answer for long are dropped
            if ($start < $now - self::KEEP_WINDOWS) {
                unset($state['windows'][$start]);

                continue;
            }

            if ($start + self::WINDOW + self::FLUSH_DELAY > $now) {
                continue;
            }

            foreach ($hostsOfWindow as $host => $window) {
                [$requests, $blocked, $errors, $duration] = $window;

                $rows[] = [
                    'start' => date('c', (int) $start),
                    'seconds' => self::WINDOW,
                    'host' => (string) $host,
                    'requests' => $requests,
                    'blocked' => $blocked,
                    'errors' => $errors,
                    'duration_ms' => $duration,
                    'histogram' => $window[4] ?? array_fill(0, count(RequestStats::BANDS) + 1, 0),
                    'endpoints' => $this->topEndpoints($window[5] ?? []),
                ];
            }
        }

        return $rows;
    }

    /**
     * Endpoints of a host in a window for the monitor: the most requested ones and the ones with the longest total
     * duration, the rest summed into one endpoint, so the window stays small also for scanners of random paths.
     *
     * @param  array  $endpoints  endpoint => [requests, duration, max, errors, url, time]
     * @return array list of ['endpoint', 'requests', 'duration_ms', 'max_ms', 'errors', 'slowest_url', 'slowest_at']
     */
    public function topEndpoints(array $endpoints): array
    {
        $byRequests = $byDuration = $endpoints;

        uasort($byRequests, fn ($a, $b) => $b[0] <=> $a[0]);
        uasort($byDuration, fn ($a, $b) => $b[1] <=> $a[1]);

        $top = array_slice($byRequests, 0, self::TOP_ENDPOINTS, true) + array_slice($byDuration, 0, self::TOP_ENDPOINTS, true);
        $rest = array_diff_key($endpoints, $top);

        if ($rest) {
            $other = [0, 0, -1, 0, '', 0];

            foreach ($rest as [$requests, $duration, $max, $errors, $url, $time]) {
                $other[0] += $requests;
                $other[1] += $duration;
                $other[3] += $errors;

                if ($max > $other[2]) {
                    [$other[2], $other[4], $other[5]] = [$max, $url, $time];
                }
            }

            $top[self::OTHER_ENDPOINTS] = $other;
        }

        $rows = [];

        foreach ($top as $endpoint => [$requests, $duration, $max, $errors, $url, $time]) {
            $rows[] = [
                'endpoint' => (string) $endpoint,
                'requests' => $requests,
                'duration_ms' => $duration,
                'max_ms' => max(0, $max),
                'errors' => $errors,
                'slowest_url' => $url,
                'slowest_at' => $time ? date('c', $time) : null,
            ];
        }

        return $rows;
    }

    /**
     * Forget the windows accepted by the monitor, their late lines are not counted again.
     *
     * @param  array  $state
     * @param  array  $rows  sent windows
     * @return void
     */
    public function markWindowsSent(array &$state, array $rows): void
    {
        foreach ($rows as $row) {
            $start = strtotime($row['start']);

            unset($state['windows'][$start]);

            $state['sent_until'] = max($state['sent_until'] ?? 0, $start + self::WINDOW);
        }
    }

    /**
     * fail2ban which bans the scanners: of this server, or of every router in front of it. Servers without
     * the shared jails are skipped until monitor:install adds them.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface|null  $output
     * @return array label => \Gogol\VpsManagerCLI\Helpers\Fail2ban
     */
    public function getFail2bans(?OutputInterface $output = null): array
    {
        $router = vpsManager()->router();
        $servers = [];

        if ($router->isEnabled()) {
            foreach ($router->getRouters() as $item) {
                $servers[$item->getLabel()] = $item->fail2ban();
            }
        } else {
            $servers['this server'] = vpsManager()->fail2ban();
        }

        foreach ($servers as $label => $fail2ban) {
            $missing = $fail2ban->isInstalled() && $fail2ban->isRunning()
                ? array_diff(SharedBans::JAILS, $fail2ban->getJails())
                : SharedBans::JAILS;

            if (count($missing)) {
                $output?->writeln('<comment>'.ucfirst($label).' has no running jails '.implode(', ', $missing).', run php vpsmanager monitor:install.</comment>');

                unset($servers[$label]);
            }
        }

        return $servers;
    }

    /**
     * Bans of the repeated scanners (recidive jail) which the monitor does not know yet: new addresses and bans
     * with another end. Every report counts in the monitor, so a ban is reported once.
     *
     * @param  array  $servers  label => Fail2ban
     * @param  array  $state  reported => [ip => expires_at]
     * @return array [list of reported rows, ip => expires_at of all current bans]
     */
    public function getNewBans(array $servers, array $state): array
    {
        $current = [];

        foreach ($servers as $fail2ban) {
            foreach (SharedBans::report($fail2ban->getBans(Fail2ban::RECIDIVE_JAIL), $fail2ban->getTimezoneOffset(), Fail2ban::RECIDIVE_JAIL) as $row) {
                $current[$row['ip']] = $row;
            }
        }

        $reported = $state['reported'] ?? [];
        $new = array_values(array_filter($current, fn ($row) => ($reported[$row['ip']] ?? null) !== $row['expires_at']));

        return [$new, array_map(fn ($row) => $row['expires_at'], $current)];
    }

    /**
     * Update the shared bans of the state by the answer of the monitor: the whole list, or its changes.
     *
     * @param  array  $state  shared => [ip => days], shared_since
     * @param  array  $answer  shared_ips of the answer: ['since', 'full', 'data' => [['ip', 'days', 'shared'], ...]]
     * @return void
     */
    public function mergeSharedBans(array &$state, array $answer): void
    {
        $shared = ! empty($answer['full']) ? [] : ($state['shared'] ?? []);

        foreach ($answer['data'] ?? [] as $row) {
            if (! filter_var($row['ip'] ?? null, FILTER_VALIDATE_IP)) {
                continue;
            }

            if ($row['shared'] ?? true) {
                $shared[$row['ip']] = (int) ($row['days'] ?? max(SharedBans::LEVELS));
            } else {
                unset($shared[$row['ip']]);
            }
        }

        $state['shared'] = $shared;
        $state['shared_since'] = $answer['since'] ?? null;
    }

    /**
     * Ban the shared addresses in the jail of their level and unban those the monitor does not share any more.
     * Only changed addresses are banned or unbanned, fail2ban is never reloaded.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  string  $label
     * @param  \Gogol\VpsManagerCLI\Helpers\Fail2ban  $fail2ban
     * @param  array  $shared  ip => days
     * @param  bool  $dryRun
     * @return bool false when fail2ban failed
     */
    public function applySharedBans(OutputInterface $output, string $label, Fail2ban $fail2ban, array $shared, bool $dryRun = false): bool
    {
        $ignored = $fail2ban->getIgnoredIps();

        // Own addresses and private networks are never banned, addresses of the recidive jail are banned already
        $skip = array_column($fail2ban->getBans(Fail2ban::RECIDIVE_JAIL), 'ip');

        foreach (array_keys($shared) as $ip) {
            if ($fail2ban->isIgnoredIp((string) $ip, $ignored)) {
                $skip[] = (string) $ip;
            }
        }

        $current = [];

        foreach (SharedBans::JAILS as $jail) {
            $current[$jail] = array_column($fail2ban->getBans($jail), 'ip');
        }

        $plan = SharedBans::plan($shared, $current, $skip);

        if (count($plan) === 0) {
            $output->writeln(ucfirst($label).': shared bans are up to date ('.count(array_merge(...array_values($current))).').');

            return true;
        }

        // Bans first, an address moving to the jail of another level is never unbanned meanwhile
        foreach ($plan as $jail => $changes) {
            if (! $dryRun && ! $fail2ban->setBans($jail, $changes['ban'])) {
                $output->writeln('<error>'.ucfirst($label).': fail2ban could not ban the addresses of '.$jail.'.</error>');

                return false;
            }
        }

        foreach ($plan as $jail => $changes) {
            if (! $dryRun && ! $fail2ban->setBans($jail, $changes['unban'], false)) {
                $output->writeln('<error>'.ucfirst($label).': fail2ban could not unban the addresses of '.$jail.'.</error>');

                return false;
            }

            $output->writeln(ucfirst($label).' '.$jail.': '.($dryRun ? 'would ban ' : 'banned ').count($changes['ban']).', '.($dryRun ? 'would unban ' : 'unbanned ').count($changes['unban']).'.');
        }

        return true;
    }

    /**
     * Content of the cron of monitor:collect: every 5 minutes at a minute of this server derived from its hostname,
     * monitor:collect --spread waits a few more seconds, so the servers do not call the monitor at the same time.
     *
     * @return string
     */
    public function getCron(): string
    {
        $minute = crc32((string) gethostname()) % 5;
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(realpath(vpsManagerPath().'/../vpsmanager') ?: vpsManagerPath().'/../vpsmanager').' monitor:collect --spread';

        return "# Sends the metrics of the server, the requests of its hosts and its bans to the monitor every 5 minutes and\n"
            ."# bans the addresses shared by the monitor. The minute is derived from the hostname.\n"
            ."#\n"
            ."# Managed by VPS Manager (monitor:install), changes are overwritten.\n\n"
            .$minute.'-59/5 * * * * root '.$command." > /dev/null 2>&1\n";
    }

    /**
     * Seconds monitor:collect --spread waits, derived from the hostname.
     *
     * @return int
     */
    public function getSpreadSeconds(): int
    {
        return intdiv(crc32((string) gethostname()), 5) % 40;
    }

    /**
     * Write the cron of monitor:collect when it differs.
     *
     * @param  bool  $dryRun
     * @return bool true when the cron has been (or would be) written
     */
    public function writeCron(bool $dryRun = false): bool
    {
        $content = $this->getCron();

        if (file_exists(self::CRON) && file_get_contents(self::CRON) === $content) {
            return false;
        }

        if (! $dryRun) {
            file_put_contents(self::CRON, $content);
            chmod(self::CRON, 0644);
        }

        return true;
    }

    /**
     * Remove the cron of monitor:collect.
     *
     * @param  bool  $dryRun
     * @return bool true when the cron has been (or would be) removed
     */
    public function removeCron(bool $dryRun = false): bool
    {
        if (! file_exists(self::CRON)) {
            return false;
        }

        return $dryRun || @unlink(self::CRON);
    }

    /**
     * Remove the old bash agent of the monitor: its crontab, its user, its directory and the setuid bit of ping
     * set by its installer (monitor:collect runs as root).
     *
     * @param  bool  $dryRun
     * @return array removed parts
     */
    public function removeLegacyAgent(bool $dryRun = false): array
    {
        $removed = [];
        $user = self::LEGACY_USER;

        if (exec('id -u '.$user.' 2> /dev/null') !== '') {
            if (! $dryRun) {
                exec('crontab -r -u '.$user.' 2> /dev/null');
                exec('userdel '.$user.' 2> /dev/null');
            }

            $removed[] = 'user '.$user.' with its crontab';
        }

        // Installed under root when the user did not exist
        exec('crontab -l -u root 2> /dev/null', $crontab, $return_var);

        if ($return_var === 0 && preg_grep('#'.preg_quote(Monitor::AGENT_SCRIPT, '#').'#', $crontab)) {
            if (! $dryRun) {
                $lines = array_filter($crontab, fn ($line) => ! str_contains($line, Monitor::AGENT_SCRIPT));
                exec('echo '.escapeshellarg(implode("\n", $lines)."\n").' | crontab -u root -');
            }

            $removed[] = 'crontab line of root';
        }

        if (is_dir(self::LEGACY_DIRECTORY)) {
            if (! $dryRun) {
                exec('rm -rf '.escapeshellarg(self::LEGACY_DIRECTORY));
            }

            $removed[] = self::LEGACY_DIRECTORY;
        }

        $ping = trim((string) exec('command -v ping 2> /dev/null'));

        if ($ping && ($real = realpath($ping)) && (fileperms($real) & 04000)) {
            if (! $dryRun) {
                chmod($real, fileperms($real) & ~04000 & 07777);
            }

            $removed[] = 'setuid of '.$real;
        }

        return $removed;
    }
}
