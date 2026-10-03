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
            self::PATH.'/jail.d/vpsmanager-scanners.conf' => (string) $this->getStub('fail2ban.scanners.conf')
                ->replace('{log_path}', self::SCANNERS_LOG)
                ->replace('{ignoreip}', implode(' ', $this->getIgnoredIps())),
        ];

        return $files;
    }

    /**
     * Addresses which are never banned: localhost and all addresses of the server, which calls
     * its own websites (e.g. an API of one hosting from another one).
     *
     * @return array
     */
    public function getIgnoredIps(): array
    {
        exec('hostname -I 2> /dev/null', $output);

        $ips = array_filter(preg_split('/\s+/', trim(implode(' ', $output))), fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP));

        return array_values(array_unique(['127.0.0.1/8', '::1', ...$ips]));
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
     * Create the log of blocked requests, fail2ban does not start a jail without its log file.
     *
     * @return void
     */
    public function ensureScannersLog(): void
    {
        if (! file_exists(self::SCANNERS_LOG)) {
            touch(self::SCANNERS_LOG);
            chmod(self::SCANNERS_LOG, 0640);
            @chown(self::SCANNERS_LOG, 'www-data');
            @chgrp(self::SCANNERS_LOG, 'adm');
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

        if ($return_var !== 0) {
            return false;
        }

        // Starting server needs a moment before it answers
        for ($i = 0; $i < 10; $i++) {
            exec('fail2ban-client ping 2> /dev/null', $ping, $pong);

            if ($pong === 0) {
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
