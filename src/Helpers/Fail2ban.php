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
     * Jail of the scanners, the recidive jail is named with the -recidive suffix.
     */
    const SCANNERS_JAIL = 'vpsmanager-scanners';

    /**
     * Check if fail2ban is installed.
     *
     * @return bool
     */
    public function isInstalled(): bool
    {
        exec('command -v fail2ban-client', $output, $return_var);

        return $return_var === 0;
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
                ->replace('{log_path}', self::SCANNERS_LOG)
                ->replace('{auth_log_path}', self::AUTH_LOG)
                ->replace('{ignoreip}', implode(' ', $this->getIgnoredIps())),
        ];

        return $files;
    }

    /**
     * Private networks, never banned. No scanner of the internet comes from them, but a router or a load
     * balancer in front of the server does: its ban would drop all websites of the server.
     */
    const PRIVATE_NETWORKS = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7'];

    /**
     * Addresses which are never banned: localhost, all addresses of the server, which calls its own
     * websites (e.g. an API of one hosting from another one), private networks and proxies trusted
     * by NGINX (set_real_ip_from).
     *
     * @return array
     */
    public function getIgnoredIps(): array
    {
        exec('hostname -I 2> /dev/null', $output);

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
        exec('grep -rhoE '.escapeshellarg('^\s*set_real_ip_from\s+[^;]+').' '.escapeshellarg($this->config('nginx_path')).' 2> /dev/null', $output);

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
            $previous = file_exists($path) ? file_get_contents($path) : null;

            if ($previous === $content) {
                continue;
            }

            if (! $dryRun && ! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }

            if ($dryRun || file_put_contents($path, $content) !== false) {
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
                @unlink($path);
            } else {
                file_put_contents($path, $previous);
            }
        }
    }

    /**
     * Remove the fail2ban files against scanners.
     *
     * @param  bool  $dryRun  only return the files which would be removed
     * @return array path => previous content
     */
    public function removeScannersFiles(bool $dryRun = false): array
    {
        $removed = [];

        foreach (array_keys($this->getScannersFiles()) as $path) {
            if (! file_exists($path)) {
                continue;
            }

            $previous = file_get_contents($path);

            if ($dryRun || @unlink($path)) {
                $removed[$path] = $previous;
            }
        }

        return $removed;
    }

    /**
     * Check if the system log of authentications exists. Debian 12 logs into journald only, then the default
     * sshd jail does not find /var/log/auth.log and fail2ban does not start at all.
     *
     * @return bool
     */
    public function hasAuthLog(): bool
    {
        return file_exists('/var/log/auth.log');
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
        foreach ([self::SCANNERS_LOG, self::AUTH_LOG] as $log) {
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
        exec('fail2ban-client -t 2>&1', $output, $return_var);

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
        exec('systemctl is-active --quiet fail2ban', $output, $active);

        exec($active === 0 ? 'fail2ban-client reload 2>&1' : 'systemctl enable --now fail2ban 2>&1', $output, $return_var);

        return $return_var === 0 && $this->waitForServer();
    }

    /**
     * Check if the fail2ban server is running.
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        exec('fail2ban-client ping 2> /dev/null', $output, $return_var);

        return $return_var === 0;
    }

    /**
     * Get names of the running jails.
     *
     * @return array
     */
    public function getJails(): array
    {
        exec('fail2ban-client status 2> /dev/null', $output);

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
        exec('fail2ban-client get '.escapeshellarg($jail).' banip --with-time 2> /dev/null', $output);

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

        exec($command.' 2> /dev/null', $output, $return_var);

        return $return_var === 0 ? (int) trim(implode('', $output)) : null;
    }

    /**
     * Count requests of the address blocked by vpsmanager/scanners.conf in the current log.
     *
     * @param  string  $ip
     * @return int
     */
    public function countScannerRequests(string $ip): int
    {
        if (! is_readable(self::SCANNERS_LOG)) {
            return 0;
        }

        // The address starts the line of the combined log format
        exec('grep -c -E '.escapeshellarg('^'.preg_quote($ip).' ').' '.escapeshellarg(self::SCANNERS_LOG).' 2> /dev/null', $output);

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
        exec('systemctl enable fail2ban 2>&1 && systemctl restart fail2ban 2>&1', $output, $return_var);

        return $return_var === 0 && $this->waitForServer();
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
        exec('fail2ban-client status '.escapeshellarg($jail).' 2>&1', $output, $return_var);

        return $return_var === 0 ? implode("\n", $output) : null;
    }
}
