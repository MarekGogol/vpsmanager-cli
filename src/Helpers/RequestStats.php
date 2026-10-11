<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

/*
 * Requests of the hosts from the access log of the monitor and the log of the blocked requests, read from the
 * last position (also across the rotation). Used by monitor:listen and monitor:collect.
 */
class RequestStats extends Application
{
    /**
     * Log of the requests blocked by the rules against scanners (format vpsmanager_fail2ban).
     */
    const SCANNERS_LOG = '/var/log/nginx/vpsmanager-scanners.log';

    /**
     * Parts of a line of the vpsmanager_fail2ban log format: address, time, status and host. NGINX escapes quotes
     * of the values (\x22), so a quoted value never contains one.
     */
    const BLOCKED_LINE = '#^(\S+) \S+ \S+ \[([^\]]+)\] "[^"]*" (\d{3}) \S+ "[^"]*" "[^"]*" "([^"]*)"$#';

    /**
     * Requests of hosts which no server section names, mostly scanners with a random Host header or an address.
     */
    const OTHER = 'other';

    /**
     * Names of the hosts served by the enabled server sections, without www. (it is counted with the domain).
     *
     * @return array name => true, wildcards as *.example.com
     */
    public function getHosts(): array
    {
        $hosts = [];

        foreach (vpsManager()->nginx()->getEnabledHostPaths() as $path) {
            $hosts += $this->parseServerNames((string) @file_get_contents($path));
        }

        return $hosts;
    }

    /**
     * Names of the server_name directives of the configuration.
     *
     * @param  string  $conf
     * @return array name => true
     */
    public function parseServerNames(string $conf): array
    {
        $hosts = [];

        preg_match_all('#^[ \t]*server_name\s+([^;]+);#m', preg_replace('/#.*$/m', '', $conf), $matches);

        foreach ($matches[1] as $names) {
            foreach (preg_split('/\s+/', trim($names)) as $name) {
                $name = trim($name, '"\'');

                // Regular expressions of subdomains (~^(?<sub>.+)\.example\.com$) are all subdomains of the domain
                if (str_starts_with($name, '~')) {
                    $name = preg_match('#\\\\\.((?:[a-z0-9-]+\\\\\.)*[a-z0-9-]+)\$$#i', $name, $match) ? '*.'.str_replace('\\.', '.', $match[1]) : '';
                }

                // Catch-all names no host
                if ($name === '' || $name === '_') {
                    continue;
                }

                // .example.com is the domain and all its subdomains
                if (str_starts_with($name, '.')) {
                    $hosts[$this->stripHost(substr($name, 1))] = true;
                    $name = '*'.$name;
                }

                $hosts[$this->stripHost($name)] = true;
            }
        }

        return $hosts;
    }

    /**
     * Host of the request as counted in the statistics: the name of its server section (subdomains of wildcards by
     * their own name), other for unknown hosts.
     *
     * @param  string  $host  $host of NGINX
     * @param  array  $hosts  getHosts()
     * @return string
     */
    public function normalizeHost(string $host, array $hosts): string
    {
        $host = $this->stripHost($host);

        if (isset($hosts[$host])) {
            return $host;
        }

        // Subdomains of wildcard names are counted by their own name
        for ($parent = $host; ($dot = strpos($parent, '.')) !== false;) {
            $parent = substr($parent, $dot + 1);

            if (isset($hosts['*.'.$parent])) {
                return $host;
            }
        }

        return self::OTHER;
    }

    /**
     * Lowercase host without the port, the trailing dot and www.
     *
     * @param  string  $host
     * @return string
     */
    public function stripHost(string $host): string
    {
        $host = rtrim(strtolower(preg_replace('#:\d+$#', '', trim($host))), '.');

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * Position at the end of the log, or the given bytes before the end (at the start of a line).
     *
     * @param  string  $path
     * @param  int  $back  bytes before the end
     * @return array ['inode' => int|null, 'offset' => int]
     */
    public function getEndPosition(string $path, int $back = 0): array
    {
        if (! ($stat = @stat($path))) {
            return ['inode' => null, 'offset' => 0];
        }

        $offset = max(0, $stat['size'] - $back);

        // Skip the rest of the line in the middle of which the position is
        if ($offset > 0 && ($handle = @fopen($path, 'r'))) {
            fseek($handle, $offset - 1);
            fgets($handle);
            $offset = ftell($handle);
            fclose($handle);
        }

        return ['inode' => $stat['ino'], 'offset' => $offset];
    }

    /**
     * New complete lines of the log since the position, which moves behind them. When the log has been rotated
     * since the position, the rest of the rotated log (path.1, compressed only by the next rotation) comes first.
     *
     * @param  string  $path
     * @param  array  $position  ['inode' => int|null, 'offset' => int], updated
     * @return \Generator of lines without the line break
     */
    public function readNewLines(string $path, array &$position): \Generator
    {
        if (! ($stat = @stat($path))) {
            return;
        }

        if (($position['inode'] ?? null) !== $stat['ino']) {
            if (isset($position['inode']) && @fileinode($path.'.1') === $position['inode']) {
                $offset = $position['offset'] ?? 0;

                yield from $this->readLines($path.'.1', $offset);
            }

            $position = ['inode' => $stat['ino'], 'offset' => 0];
        }

        // The log has been truncated
        if ($stat['size'] < $position['offset']) {
            $position['offset'] = 0;
        }

        yield from $this->readLines($path, $position['offset']);
    }

    /**
     * Complete lines of the file from the offset, which moves behind them. A line still being written stays.
     *
     * @param  string  $path
     * @param  int  $offset  updated
     * @return \Generator
     */
    protected function readLines(string $path, int &$offset): \Generator
    {
        if (! ($handle = @fopen($path, 'r'))) {
            return;
        }

        fseek($handle, $offset);

        while (($line = fgets($handle)) !== false && str_ends_with($line, "\n")) {
            $offset += strlen($line);

            yield rtrim($line, "\r\n");
        }

        fclose($handle);
    }

    /**
     * Parse a line of the access log of the monitor (format vpsmanager_monitor).
     *
     * @param  string  $line
     * @return array|null ['time', 'host', 'status', 'duration'] (duration in seconds)
     */
    public function parseRequest(string $line): ?array
    {
        if (! preg_match(Monitor::LINE, $line, $m) || ($time = strtotime($m[2])) === false) {
            return null;
        }

        return ['time' => $time, 'host' => $m[3], 'status' => (int) $m[6], 'duration' => (float) $m[8]];
    }

    /**
     * Parse a line of the log of the blocked requests (format vpsmanager_fail2ban).
     *
     * @param  string  $line
     * @return array|null ['time', 'host']
     */
    public function parseBlocked(string $line): ?array
    {
        if (! preg_match(self::BLOCKED_LINE, $line, $m) || ! ($time = \DateTime::createFromFormat('d/M/Y:H:i:s O', $m[2]))) {
            return null;
        }

        return ['time' => $time->getTimestamp(), 'host' => $m[4]];
    }
}
