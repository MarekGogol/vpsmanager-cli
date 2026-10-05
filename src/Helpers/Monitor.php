<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

class Monitor extends Application
{
    /**
     * Access log of the monitor, written by NGINX (vpsmanager/monitor.conf).
     */
    const LOG = '/var/log/vpsmanager/access.log';

    /**
     * Configuration of the hourly rotation, outside of /etc/logrotate.d so the daily logrotate does not touch it.
     */
    const LOGROTATE = '/etc/vpsmanager/logrotate-monitor.conf';

    /**
     * Hourly rotation of the log.
     */
    const CRON = '/etc/cron.hourly/vpsmanager-monitor';

    /**
     * Size of the log which is rotated within the day.
     */
    const MAX_SIZE = '100M';

    /**
     * Parts of a line of the vpsmanager_monitor log format.
     */
    const LINE = '#^(\S+) \[([^\]]+)\] "([^"]*)" "(\S+) ([^"]*)" (\d{3}) (\d+) ([\d.]+) "([^"]*)" "([^"]*)"$#';

    /**
     * Get the files of the log rotation, path => content.
     *
     * @return array
     */
    public function getFiles(): array
    {
        $resources = __DIR__.'/../Resources/monitor';

        return [
            static::LOGROTATE => str_replace('{max_size}', self::MAX_SIZE, file_get_contents($resources.'/logrotate.conf')),
            self::CRON => file_get_contents($resources.'/cron.sh'),
        ];
    }

    /**
     * Write the files of the log rotation when they differ.
     *
     * @param  bool  $dryRun  only return the files which would be written
     * @return array path => previous content (null for a new file)
     */
    public function writeFiles(bool $dryRun = false): array
    {
        $written = [];

        foreach ($this->getFiles() as $path => $content) {
            $previous = file_exists($path) ? file_get_contents($path) : null;

            if ($previous === $content) {
                continue;
            }

            if (! $dryRun) {
                if (! is_dir(dirname($path))) {
                    mkdir(dirname($path), 0755, true);
                }

                file_put_contents($path, $content);

                // run-parts runs only executable files
                chmod($path, $path === self::CRON ? 0755 : 0644);
            }

            $written[$path] = $previous;
        }

        return $written;
    }

    /**
     * Remove the files of the log rotation. The log stays until it is deleted by hand.
     *
     * @return array removed paths
     */
    public function removeFiles(): array
    {
        $removed = [];

        foreach (array_keys($this->getFiles()) as $path) {
            if (file_exists($path) && @unlink($path)) {
                $removed[] = $path;
            }
        }

        return $removed;
    }

    /**
     * Create the directory of the log, NGINX creates the file itself. Workers of NGINX (www-data) reopen the log
     * after the rotation, they need to enter the directory, otherwise they keep writing into the rotated log.
     *
     * @param  bool  $dryRun  only check if the directory would be created or fixed
     * @return bool true when the directory has been (or would be) created or fixed
     */
    public function ensureLogDirectory(bool $dryRun = false): bool
    {
        $directory = dirname(static::LOG);

        if (is_dir($directory) && (fileperms($directory) & 0777) === 0755) {
            return false;
        }

        if (! $dryRun) {
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            chmod($directory, 0755);
            @chgrp($directory, 'adm');
        }

        return true;
    }

    /**
     * Parse requests of the log since the given time, rotated logs included (also compressed).
     *
     * @param  int  $since  unix timestamp
     * @return \Generator of ['ip', 'time', 'host', 'method', 'url', 'status', 'bytes', 'duration', 'referer', 'agent']
     */
    public function readRequests(int $since): \Generator
    {
        $files = array_filter(glob(static::LOG.'*'), fn ($file) => filemtime($file) >= $since);

        // Oldest files first
        usort($files, fn ($a, $b) => filemtime($a) <=> filemtime($b));

        foreach ($files as $file) {
            $handle = str_ends_with($file, '.gz') ? gzopen($file, 'r') : fopen($file, 'r');

            if (! $handle) {
                continue;
            }

            while (($line = str_ends_with($file, '.gz') ? gzgets($handle) : fgets($handle)) !== false) {
                if (! preg_match(self::LINE, rtrim($line, "\n"), $m) || ($time = strtotime($m[2])) < $since) {
                    continue;
                }

                yield [
                    'ip' => $m[1],
                    'time' => $time,
                    'host' => $m[3],
                    'method' => $m[4],
                    'url' => $m[5],
                    'status' => (int) $m[6],
                    'bytes' => (int) $m[7],
                    'duration' => (float) $m[8],
                    'referer' => $m[9],
                    'agent' => $m[10],
                ];
            }

            str_ends_with($file, '.gz') ? gzclose($handle) : fclose($handle);
        }
    }

    /**
     * Summary of the requests for the analysis of addresses and urls which should be banned.
     *
     * @param  int  $since  unix timestamp
     * @param  array  $banned  addresses banned by fail2ban now
     * @param  array  $ignored  addresses of the server, never suspicious (fail2ban ignores them too)
     * @param  int  $limit  rows of each list
     * @return array
     */
    public function report(int $since, array $banned, array $ignored = [], int $limit = 20): array
    {
        $banned = array_flip($banned);
        $ignored = array_flip($ignored);
        $total = 0;
        $statuses = $hosts = $ips = $errors = $paths = $agents = $minutes = [];

        foreach ($this->readRequests($since) as $request) {
            $total++;
            $ip = $request['ip'];
            $path = parse_url($request['url'], PHP_URL_PATH) ?: $request['url'];
            $failed = $request['status'] >= 400;

            $statuses[intdiv($request['status'], 100).'xx'] = ($statuses[intdiv($request['status'], 100).'xx'] ?? 0) + 1;
            $hosts[$request['host']] = ($hosts[$request['host']] ?? 0) + 1;

            $ips[$ip] ??= ['requests' => 0, 'errors' => 0, 'hosts' => [], 'paths' => []];
            $ips[$ip]['requests']++;
            $ips[$ip]['hosts'][$request['host']] = true;

            if ($failed) {
                $ips[$ip]['errors']++;

                if (count($ips[$ip]['paths']) < 5) {
                    $ips[$ip]['paths'][$path] = true;
                }

                $errors[$request['status']] = ($errors[$request['status']] ?? 0) + 1;
                $paths[$path] ??= ['requests' => 0, 'ips' => [], 'status' => $request['status']];
                $paths[$path]['requests']++;
                $paths[$path]['ips'][$ip] = true;

                $agents[$request['agent']] = ($agents[$request['agent']] ?? 0) + 1;
            }

            $minute = $ip.'|'.intdiv($request['time'], 60);
            $minutes[$minute] = ($minutes[$minute] ?? 0) + 1;
        }

        // Highest number of requests of an address within one minute
        $peaks = [];

        foreach ($minutes as $key => $count) {
            $ip = explode('|', $key)[0];
            $peaks[$ip] = max($peaks[$ip] ?? 0, $count);
        }

        $notBanned = array_filter($ips, fn ($data, $ip) => ! isset($banned[$ip]), ARRAY_FILTER_USE_BOTH);

        $suspicious = array_filter($notBanned, fn ($data, $ip) => $data['errors'] > 0 && ! isset($ignored[$ip]) && ! str_starts_with($ip, '127.'), ARRAY_FILTER_USE_BOTH);
        uasort($suspicious, fn ($a, $b) => $b['errors'] <=> $a['errors']);

        uasort($paths, fn ($a, $b) => count($b['ips']) <=> count($a['ips']) ?: $b['requests'] <=> $a['requests']);
        arsort($hosts);
        arsort($agents);
        arsort($peaks);
        ksort($statuses);
        ksort($errors);

        return [
            'since' => $this->formatTime($since),
            'requests' => $total,
            'addresses' => count($ips),
            'banned_addresses_seen' => count($ips) - count($notBanned),
            'statuses' => $statuses,
            'errors' => $errors,
            'hosts' => array_slice($hosts, 0, $limit, true),
            'suspicious_addresses' => array_map(fn ($data, $ip) => [
                'ip' => $ip,
                'errors' => $data['errors'],
                'requests' => $data['requests'],
                'peak_per_minute' => $peaks[$ip],
                'hosts' => count($data['hosts']),
                'paths' => implode(' ', array_keys($data['paths'])),
            ], array_slice($suspicious, 0, $limit, true), array_keys(array_slice($suspicious, 0, $limit, true))),
            'failed_paths' => array_map(fn ($data, $path) => [
                'path' => $path,
                'status' => $data['status'],
                'requests' => $data['requests'],
                'addresses' => count($data['ips']),
            ], array_slice($paths, 0, $limit, true), array_keys(array_slice($paths, 0, $limit, true))),
            'busiest_addresses' => array_map(fn ($peak, $ip) => [
                'ip' => $ip,
                'peak_per_minute' => $peak,
                'requests' => $ips[$ip]['requests'],
                'errors' => $ips[$ip]['errors'],
                'banned' => isset($banned[$ip]),
            ], array_slice($peaks, 0, $limit, true), array_keys(array_slice($peaks, 0, $limit, true))),
            'failing_agents' => array_slice($agents, 0, $limit, true),
        ];
    }

    /**
     * Format the time in the timezone of the server, PHP CLI often runs in UTC.
     *
     * @param  int  $time
     * @return string
     */
    public function formatTime(int $time): string
    {
        exec('date -d @'.$time.' "+%Y-%m-%d %H:%M %Z" 2> /dev/null', $output, $return_var);

        return $return_var === 0 && isset($output[0]) ? $output[0] : date('Y-m-d H:i T', $time);
    }
}
