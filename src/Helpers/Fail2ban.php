<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

class Fail2ban extends Application
{
    /**
     * Directory of the fail2ban configuration.
     */
    const PATH = '/etc/fail2ban';

    /**
     * Log of the requests blocked by vpsmanager/scanners.conf, written by NGINX.
     */
    const SCANNERS_LOG = '/var/log/nginx/vpsmanager-scanners.log';

    /**
     * Log of the requests refused by auth_basic, written by NGINX (vpsmanager/monitor.conf).
     */
    const AUTH_LOG = '/var/log/nginx/vpsmanager-auth.log';

    /**
     * Logs of the router, written by rsyslog from the requests which NGINX of the servers behind it sends.
     */
    const ROUTER_SCANNERS_LOG = '/var/log/vpsmanager/scanners.log';

    const ROUTER_AUTH_LOG = '/var/log/vpsmanager/auth.log';

    /**
     * Log of the jail of the shared bans, nothing writes into it, monitor:sync bans the addresses directly.
     */
    const SHARED_LOG = '/var/log/vpsmanager/shared.log';

    /**
     * Jail of the scanners, the recidive jail is named with the -recidive suffix.
     */
    const SCANNERS_JAIL = 'vpsmanager-scanners';

    /**
     * Jail of the scanners banned again and again, its bans are shared with the other servers.
     */
    const RECIDIVE_JAIL = 'vpsmanager-scanners-recidive';

    /**
     * Jail of the addresses banned by the other servers (monitor:sync).
     */
    const SHARED_JAIL = 'vpsmanager-shared';

    /**
     * Private networks, never banned. No scanner of the internet comes from them, but a router or a load
     * balancer in front of the server does: its ban would drop all websites of the server.
     */
    const PRIVATE_NETWORKS = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7'];

    /**
     * SSH destination of the router when this instance manages fail2ban of the router, e.g. ssh://root@192.168.1.1:1000.
     *
     * @var string|null
     */
    protected ?string $remote = null;

    /**
     * Get the fail2ban helper of the router in front of this server, all commands and files go over SSH.
     *
     * @param  string  $destination  e.g. ssh://root@192.168.1.1:1000
     * @return static
     */
    public function onRouter(string $destination): static
    {
        $router = clone $this;
        $router->remote = $destination;

        return $router;
    }

    /**
     * Check if this helper manages fail2ban of the router.
     *
     * @return bool
     */
    public function isRouter(): bool
    {
        return $this->remote !== null;
    }

    /**
     * Run the shell command here, or on the router over SSH.
     *
     * @param  string  $command
     * @param  array|null  $output
     * @return int exit code
     */
    public function run(string $command, ?array &$output = null): int
    {
        $output = [];

        if ($this->remote) {
            $command = $this->sshCommand().' '.escapeshellarg($command);
        }

        exec($command, $output, $return_var);

        return $return_var;
    }

    /**
     * SSH command of the router, it never asks for a password.
     *
     * @return string
     */
    protected function sshCommand(): string
    {
        return 'ssh -o BatchMode=yes -o ConnectTimeout=10 -o StrictHostKeyChecking=accept-new '.escapeshellarg($this->remote);
    }

    /**
     * Check if the router answers over SSH.
     *
     * @return bool
     */
    public function isReachable(): bool
    {
        return $this->run('true 2> /dev/null') === 0;
    }

    /**
     * Read the file, null when it does not exist.
     *
     * @param  string  $path
     * @return string|null
     */
    public function readFile(string $path): ?string
    {
        if (! $this->remote) {
            return file_exists($path) ? file_get_contents($path) : null;
        }

        // The marker keeps a trailing newline of the file, which exec() would drop
        if ($this->run('[ -f '.escapeshellarg($path).' ] && cat '.escapeshellarg($path).' && echo __vpsm_eof__', $output) !== 0) {
            return null;
        }

        array_pop($output);

        return count($output) ? implode("\n", $output)."\n" : '';
    }

    /**
     * Write the file, its directory is created when it is missing.
     *
     * @param  string  $path
     * @param  string  $content
     * @return bool
     */
    public function putFile(string $path, string $content): bool
    {
        if (! $this->remote) {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }

            return file_put_contents($path, $content) !== false;
        }

        $command = 'mkdir -p '.escapeshellarg(dirname($path)).' && cat > '.escapeshellarg($path);
        $process = proc_open($this->sshCommand().' '.escapeshellarg($command), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            return false;
        }

        fwrite($pipes[0], $content);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        return proc_close($process) === 0;
    }

    /**
     * Delete the file.
     *
     * @param  string  $path
     * @return bool
     */
    public function deleteFile(string $path): bool
    {
        return $this->remote ? $this->run('rm -f '.escapeshellarg($path)) === 0 : @unlink($path);
    }

    /**
     * Check if fail2ban is installed.
     *
     * @return bool
     */
    public function isInstalled(): bool
    {
        return $this->run('command -v fail2ban-client > /dev/null') === 0;
    }

    /**
     * Get the fail2ban files against scanners, path => content.
     *
     * @return array
     */
    public function getScannersFiles(): array
    {
        $resources = __DIR__.'/../Resources/fail2ban';

        $files = [
            self::PATH.'/filter.d/vpsmanager-scanners.conf' => file_get_contents($resources.'/filter.d/vpsmanager-scanners.conf'),
            self::PATH.'/filter.d/vpsmanager-scanners-recidive.conf' => file_get_contents($resources.'/filter.d/vpsmanager-scanners-recidive.conf'),
            self::PATH.'/filter.d/vpsmanager-http-auth.conf' => file_get_contents($resources.'/filter.d/vpsmanager-http-auth.conf'),
            self::PATH.'/fail2ban.d/vpsmanager.conf' => file_get_contents($resources.'/fail2ban.d/vpsmanager.conf'),
            self::PATH.'/jail.d/vpsmanager-scanners.conf' => (string) $this->getStub('fail2ban.scanners.conf')
                ->replace('{log_path}', $this->getScannersLog())
                ->replace('{auth_log_path}', $this->getAuthLog())
                ->replace('{shared_log_path}', self::SHARED_LOG)
                ->replace('{ignoreip}', implode(' ', $this->getIgnoredIps())),
        ];

        return $files;
    }

    /**
     * Log of the blocked requests, which the scanners jail reads.
     *
     * @return string
     */
    public function getScannersLog(): string
    {
        return $this->remote ? self::ROUTER_SCANNERS_LOG : self::SCANNERS_LOG;
    }

    /**
     * Log of the requests refused by auth_basic, which the http-auth jail reads.
     *
     * @return string
     */
    public function getAuthLog(): string
    {
        return $this->remote ? self::ROUTER_AUTH_LOG : self::AUTH_LOG;
    }

    /**
     * Addresses which are never banned: localhost, all addresses of the server, which calls its own
     * websites (e.g. an API of one hosting from another one), private networks and proxies trusted
     * by NGINX (set_real_ip_from).
     *
     * @return array
     */
    public function getIgnoredIps(): array
    {
        // BusyBox of the router (Alpine) has no hostname -I
        $this->run('hostname -I 2> /dev/null || ip -o addr show | awk \'{split($4, a, "/"); print a[1]}\'', $output);

        $ips = array_filter(preg_split('/\s+/', trim(implode(' ', $output))), fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP));

        return array_values(array_unique(['127.0.0.1/8', '::1', ...$ips, ...self::PRIVATE_NETWORKS, ...$this->getTrustedProxies()]));
    }

    /**
     * Addresses of proxies in front of the server, whose requests carry the address of the visitor
     * (set_real_ip_from of the NGINX configuration, e.g. a load balancer with a public address).
     *
     * @return array
     */
    public function getTrustedProxies(): array
    {
        $this->run('grep -rhoE '.escapeshellarg('^\s*set_real_ip_from\s+[^;]+').' '.escapeshellarg($this->config('nginx_path')).' 2> /dev/null', $output);

        $proxies = array_map(fn ($line) => preg_replace('/^\s*set_real_ip_from\s+/', '', $line), $output);

        return array_values(array_unique(array_filter($proxies, function ($proxy) {
            [$ip, $mask] = array_pad(explode('/', $proxy, 2), 2, null);

            return filter_var($ip, FILTER_VALIDATE_IP) && ($mask === null || ctype_digit($mask));
        })));
    }

    /**
     * Write the given files when their content differs.
     *
     * @param  array  $files  path => content
     * @param  bool  $dryRun  only return the files which would be written
     * @return array path => previous content (null for a new file)
     */
    public function writeFiles(array $files, bool $dryRun = false): array
    {
        $written = [];

        foreach ($files as $path => $content) {
            $previous = $this->readFile($path);

            if ($previous === $content) {
                continue;
            }

            if ($dryRun || $this->putFile($path, $content)) {
                $written[$path] = $previous;
            }
        }

        return $written;
    }

    /**
     * Restore files written by writeFiles() or removed by removeFiles().
     *
     * @param  array  $written  path => previous content (null for a new file)
     * @return void
     */
    public function restoreFiles(array $written): void
    {
        foreach ($written as $path => $previous) {
            if ($previous === null) {
                $this->deleteFile($path);
            } else {
                $this->putFile($path, $previous);
            }
        }
    }

    /**
     * Remove the given files.
     *
     * @param  array  $paths
     * @param  bool  $dryRun  only return the files which would be removed
     * @return array path => previous content
     */
    public function removeFiles(array $paths, bool $dryRun = false): array
    {
        $removed = [];

        foreach ($paths as $path) {
            if (($previous = $this->readFile($path)) === null) {
                continue;
            }

            if ($dryRun || $this->deleteFile($path)) {
                $removed[$path] = $previous;
            }
        }

        return $removed;
    }

    /**
     * Remove the fail2ban files against scanners.
     *
     * @param  bool  $dryRun  only return the files which would be removed
     * @return array path => previous content
     */
    public function removeScannersFiles(bool $dryRun = false): array
    {
        return $this->removeFiles(array_keys($this->getScannersFiles()), $dryRun);
    }

    /**
     * Check if the system log of authentications exists. Debian 12 logs into journald only, then the default
     * sshd jail does not find /var/log/auth.log and fail2ban does not start at all.
     *
     * @return bool
     */
    public function hasAuthLog(): bool
    {
        // The sshd jail of Alpine (router) reads /var/log/messages
        return $this->remote || file_exists('/var/log/auth.log');
    }

    /**
     * Bring back /var/log/auth.log with rsyslog, which writes the logs of journald into the classic files.
     *
     * @return bool
     */
    public function installAuthLog(): bool
    {
        exec('DEBIAN_FRONTEND=noninteractive apt-get install -y rsyslog 2>&1 && systemctl enable --now rsyslog 2>&1', $output, $return_var);

        if ($return_var !== 0) {
            $this->response()->error(implode("\n", array_slice($output, -10)))->writeln();

            return false;
        }

        // rsyslog creates the file with the first message, fail2ban needs it right away
        if (! file_exists('/var/log/auth.log')) {
            touch('/var/log/auth.log');
            chmod('/var/log/auth.log', 0640);
            @chgrp('/var/log/auth.log', 'adm');
        }

        return true;
    }

    /**
     * Create the logs of blocked and refused requests, fail2ban does not start a jail without its log file.
     *
     * @return void
     */
    public function ensureScannersLog(): void
    {
        if ($this->remote) {
            $logs = implode(' ', array_map('escapeshellarg', [self::ROUTER_SCANNERS_LOG, self::ROUTER_AUTH_LOG, self::SHARED_LOG]));

            $this->run('mkdir -p '.escapeshellarg(dirname(self::ROUTER_SCANNERS_LOG)).' && touch '.$logs.' && chmod 0640 '.$logs);

            return;
        }

        foreach ([self::SCANNERS_LOG, self::AUTH_LOG, self::SHARED_LOG] as $log) {
            if (! is_dir(dirname($log))) {
                mkdir(dirname($log), 0755, true);
            }

            if (! file_exists($log)) {
                touch($log);
                chmod($log, 0640);
                @chown($log, 'www-data');
                @chgrp($log, 'adm');
            }
        }
    }

    /**
     * Test the fail2ban configuration.
     *
     * @return bool
     */
    public function test(): bool
    {
        $return_var = $this->run('fail2ban-client -t 2>&1', $output);

        if ($return_var !== 0) {
            $this->response()->error(implode("\n", array_slice($output, -10)))->writeln();
        }

        return $return_var === 0;
    }

    /**
     * Reload fail2ban, or start it when it is not running.
     *
     * @return bool
     */
    public function reload(): bool
    {
        $command = $this->isRunning() ? 'fail2ban-client reload 2>&1' : $this->service('start');

        return $this->run($command) === 0 && $this->waitForServer();
    }

    /**
     * Command of the fail2ban service, systemd of Debian or OpenRC of Alpine (router). It also starts
     * fail2ban with the system.
     *
     * @param  string  $action  start or restart
     * @return string
     */
    protected function service(string $action): string
    {
        return 'if command -v systemctl > /dev/null; then systemctl enable fail2ban 2>&1 && systemctl '.$action.' fail2ban 2>&1; '
            .'else rc-update add fail2ban default > /dev/null 2>&1; rc-service fail2ban '.$action.' 2>&1; fi';
    }

    /**
     * Check if the fail2ban server is running.
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->run('fail2ban-client ping 2> /dev/null') === 0;
    }

    /**
     * Get names of the running jails.
     *
     * @return array
     */
    public function getJails(): array
    {
        $this->run('fail2ban-client status 2> /dev/null', $output);

        foreach ($output as $line) {
            if (preg_match('/Jail list:\s*(.*)$/', $line, $matches)) {
                return array_values(array_filter(array_map('trim', explode(',', $matches[1]))));
            }
        }

        return [];
    }

    /**
     * Get banned addresses of the jail with the time of the ban and of its end.
     *
     * @param  string  $jail
     * @return array list of ['ip' => ..., 'banned_at' => ..., 'expires_at' => ...]
     */
    public function getBans(string $jail): array
    {
        $this->run('fail2ban-client get '.escapeshellarg($jail).' banip --with-time 2> /dev/null', $output);

        $bans = [];

        foreach ($output as $line) {
            // 203.0.113.10 	2026-10-03 16:15:53 + 3600 = 2026-10-03 17:15:53
            if (preg_match('/^(\S+)\s+(\S+ \S+)\s+\+\s+-?\d+\s+=\s+(\S+ \S+)/', trim($line), $matches)) {
                $bans[] = ['ip' => $matches[1], 'banned_at' => $matches[2], 'expires_at' => $matches[3]];
            }
        }

        return $bans;
    }

    /**
     * Unban the address in all jails, or only in the given one.
     *
     * @param  string  $ip
     * @param  string|null  $jail
     * @return int|null number of removed bans, null when fail2ban failed
     */
    public function unban(string $ip, ?string $jail = null): ?int
    {
        $command = $jail
            ? 'fail2ban-client set '.escapeshellarg($jail).' unbanip '.escapeshellarg($ip)
            : 'fail2ban-client unban '.escapeshellarg($ip);

        $return_var = $this->run($command.' 2> /dev/null', $output);

        return $return_var === 0 ? (int) trim(implode('', $output)) : null;
    }

    /**
     * Ban or unban the addresses in the jail, without any reload of fail2ban.
     *
     * @param  string  $jail
     * @param  array  $ips
     * @param  bool  $ban  false unbans
     * @return bool
     */
    public function setBans(string $jail, array $ips, bool $ban = true): bool
    {
        foreach (array_chunk(array_values($ips), 100) as $chunk) {
            $command = 'fail2ban-client set '.escapeshellarg($jail).' '.($ban ? 'banip' : 'unbanip').' '.implode(' ', array_map('escapeshellarg', $chunk));

            if ($this->run($command.' > /dev/null 2>&1') !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if the address is never banned by this server or router (localhost, own addresses, private networks...).
     *
     * @param  string  $ip
     * @param  array|null  $ignored  result of getIgnoredIps(), read when not given
     * @return bool
     */
    public function isIgnoredIp(string $ip, ?array $ignored = null): bool
    {
        foreach ($ignored ?? $this->getIgnoredIps() as $network) {
            [$address, $mask] = array_pad(explode('/', $network, 2), 2, null);

            $ipBinary = @inet_pton($ip);
            $networkBinary = @inet_pton($address);

            if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) {
                continue;
            }

            $bits = $mask === null ? strlen($ipBinary) * 8 : (int) $mask;
            $bytes = intdiv($bits, 8);

            if (substr($ipBinary, 0, $bytes) !== substr($networkBinary, 0, $bytes)) {
                continue;
            }

            $rest = $bits % 8;

            if ($rest === 0 || ((ord($ipBinary[$bytes]) ^ ord($networkBinary[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count requests of the address blocked by vpsmanager/scanners.conf in the current log.
     *
     * @param  string  $ip
     * @return int
     */
    public function countScannerRequests(string $ip): int
    {
        // The address starts the line of the combined log format
        $this->run('grep -c -E '.escapeshellarg('^'.preg_quote($ip).' ').' '.escapeshellarg($this->getScannersLog()).' 2> /dev/null', $output);

        return (int) ($output[0] ?? 0);
    }

    /**
     * Restart fail2ban. Unlike reload, the start applies the bans of the database again with the current actions
     * of the jails, which reload only flushes when an action changes.
     *
     * @return bool
     */
    public function restart(): bool
    {
        return $this->run($this->service('restart')) === 0 && $this->waitForServer();
    }

    /**
     * Wait until the started fail2ban server answers.
     *
     * @return bool
     */
    protected function waitForServer(): bool
    {
        for ($i = 0; $i < 15; $i++) {
            if ($this->isRunning()) {
                return true;
            }

            sleep(1);
        }

        return false;
    }

    /**
     * Get the status of the jail, e.g. number of banned addresses.
     *
     * @param  string  $jail
     * @return string|null
     */
    public function status(string $jail): ?string
    {
        $return_var = $this->run('fail2ban-client status '.escapeshellarg($jail).' 2>&1', $output);

        return $return_var === 0 ? implode("\n", $output) : null;
    }
}
