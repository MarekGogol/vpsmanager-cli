<?php

namespace Gogol\VpsManagerCLI\Helpers;

/*
 * Old file sessions of the Laravel applications of the hostings (sessions:clean). Laravel removes them only by
 * the lottery of its requests (2 of 100), which does not keep up with hundreds of thousands of files (2.2 GB
 * on trinityfinance.sk, 5.9 GB on cech1990.sk).
 *
 * A file older than the lifetime is dead already: Laravel does not read a session whose file has not been
 * changed for the lifetime, and every request writes the file of an active session. The lifetime is read from
 * config/session.php of each application (src/Resources/sessions/session-config.php), so a value hard coded
 * in the config wins over .env, as in Laravel.
 */
class Sessions
{
    /**
     * Younger files are never removed, even with a shorter lifetime, a margin for a misread value.
     */
    const MIN_MINUTES = 1440;

    /**
     * Log of the runs.
     */
    const LOG = '/var/log/vpsmanager/sessions-clean.log';

    /**
     * Lock of a running sessions:clean.
     */
    const LOCK = '/var/lib/vpsmanager/sessions-clean.lock';

    /**
     * Seconds allowed to read the configuration of one application.
     */
    const TIMEOUT = 20;

    /**
     * Laravel applications of the hostings: data/web and data/sub/* with an artisan and a config/session.php.
     *
     * @param  string  $www
     * @return array
     */
    public static function apps(string $www): array
    {
        $www = rtrim($www, '/');

        $apps = array_filter(
            array_merge(glob($www.'/*/data/web', GLOB_ONLYDIR) ?: [], glob($www.'/*/data/sub/*', GLOB_ONLYDIR) ?: []),
            fn ($path) => is_file($path.'/artisan') && is_file($path.'/config/session.php'),
        );

        sort($apps);

        return array_values($apps);
    }

    /**
     * Path of the script which prints the session configuration of an application.
     *
     * @return string
     */
    public static function scriptPath(): string
    {
        return dirname(__DIR__).'/Resources/sessions/session-config.php';
    }

    /**
     * Command which reads the session configuration of the application, as the owner of the application when
     * root runs it, so the config of a hosting never runs as root. The script is given on the standard input,
     * the owner cannot read the directory of VPS Manager.
     *
     * @param  string  $app
     * @param  string|null  $user  owner of the application, null to run as the current user
     * @return array
     */
    public static function readCommand(string $app, ?string $user): array
    {
        // coreutils of the servers have timeout, a Mac has not
        $command = trim((string) shell_exec('command -v timeout 2> /dev/null')) !== '' ? ['timeout', (string) self::TIMEOUT] : [];

        if ($user !== null) {
            array_push($command, 'runuser', '-u', $user, '--');
        }

        return array_merge($command, [PHP_BINARY, '-d', 'display_errors=stderr', '--', $app]);
    }

    /**
     * Read the session configuration of the application.
     *
     * @param  string  $app
     * @return array ['driver' => …, 'lifetime' => …, 'files' => …] or ['error' => …]
     */
    public static function readConfig(string $app): array
    {
        $user = null;

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $owner = fileowner($app);
            $user = $owner === false ? null : (posix_getpwuid($owner)['name'] ?? null);

            if ($user === null) {
                return ['error' => 'the owner of the application is unknown'];
            }
        }

        $process = proc_open(static::readCommand($app, $user), [0 => ['file', static::scriptPath(), 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $app);

        if (! is_resource($process)) {
            return ['error' => 'php could not be started'];
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return static::parseOutput((string) $stdout, $stderr, $code);
    }

    /**
     * Parse the output of the script.
     *
     * @param  string  $stdout
     * @param  string  $stderr
     * @param  int  $code
     * @return array
     */
    public static function parseOutput(string $stdout, string $stderr, int $code): array
    {
        $data = json_decode(trim($stdout), true);

        if (is_array($data) && isset($data['error'])) {
            return ['error' => (string) $data['error']];
        }

        if ($code !== 0 || ! is_array($data)) {
            $message = $code === 124 ? 'reading of the config timed out' : 'the config could not be read';
            $detail = strtok($stderr !== '' ? $stderr : trim($stdout), "\n");

            return ['error' => $message.($detail ? ': '.$detail : '')];
        }

        return [
            'driver' => is_string($data['driver'] ?? null) ? $data['driver'] : null,
            'lifetime' => $data['lifetime'] ?? null,
            'files' => is_string($data['files'] ?? null) ? $data['files'] : null,
        ];
    }

    /**
     * Lifetime in whole minutes, null when it is not a positive number.
     *
     * @param  mixed  $lifetime  e.g. 120, "120", 262080
     * @return int|null
     */
    public static function lifetimeMinutes(mixed $lifetime): ?int
    {
        if (is_bool($lifetime) || ! is_numeric($lifetime) || $lifetime <= 0) {
            return null;
        }

        return (int) ceil((float) $lifetime);
    }

    /**
     * Age in minutes after which a file of the sessions is removed.
     *
     * @param  int  $lifetime
     * @return int
     */
    public static function removeAfter(int $lifetime): int
    {
        return max($lifetime, self::MIN_MINUTES);
    }

    /**
     * Directory of the sessions of the application, null when it is not inside the storage of the application.
     *
     * @param  string  $app
     * @param  string|null  $files  'files' of config/session.php
     * @return string|null
     */
    public static function directory(string $app, ?string $files): ?string
    {
        $directory = realpath($files ?: $app.'/storage/framework/sessions');
        $storage = realpath($app.'/storage');

        if ($directory === false || $storage === false || ! is_dir($directory) || ! str_starts_with($directory.'/', $storage.'/')) {
            return null;
        }

        return $directory;
    }

    /**
     * Decide what to do with the application from its configuration.
     *
     * @param  string  $app
     * @param  array  $config  output of readConfig()
     * @return array ['skip' => reason] or ['directory' => …, 'lifetime' => minutes, 'after' => minutes]
     */
    public static function plan(string $app, array $config): array
    {
        if (isset($config['error'])) {
            return ['skip' => $config['error']];
        }

        if (($config['driver'] ?? null) !== 'file') {
            return ['skip' => 'driver '.($config['driver'] ?? 'unknown')];
        }

        $lifetime = static::lifetimeMinutes($config['lifetime'] ?? null);

        if ($lifetime === null) {
            return ['skip' => 'lifetime '.var_export($config['lifetime'] ?? null, true).' is not a positive number'];
        }

        $directory = static::directory($app, $config['files'] ?? null);

        if ($directory === null) {
            return ['skip' => 'the directory of the sessions is missing or outside storage/'];
        }

        return ['directory' => $directory, 'lifetime' => $lifetime, 'after' => static::removeAfter($lifetime)];
    }

    /**
     * Remove the files of the sessions not changed for the given minutes. Dot files (.gitignore), links and
     * directories are kept.
     *
     * @param  string  $directory
     * @param  int  $minutes
     * @param  bool  $dryRun
     * @param  int|null  $now
     * @return array ['files' => all files, 'removed' => …, 'bytes' => disk space of the removed files, 'failed' => …]
     */
    public static function clean(string $directory, int $minutes, bool $dryRun = false, ?int $now = null): array
    {
        $cutoff = ($now ?? time()) - $minutes * 60;
        $result = ['files' => 0, 'removed' => 0, 'bytes' => 0, 'failed' => 0];

        $handle = @opendir($directory);

        if ($handle === false) {
            return $result;
        }

        while (($name = readdir($handle)) !== false) {
            if ($name[0] === '.') {
                continue;
            }

            $path = $directory.'/'.$name;
            $stat = @lstat($path);

            // Only regular files, a link or a directory is never followed
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
                continue;
            }

            $result['files']++;

            if ($stat['mtime'] >= $cutoff) {
                continue;
            }

            if ($dryRun || @unlink($path)) {
                $result['removed']++;
                $result['bytes'] += $stat['blocks'] > 0 ? $stat['blocks'] * 512 : $stat['size'];
            } else {
                $result['failed']++;
            }
        }

        closedir($handle);

        return $result;
    }

    /**
     * Human readable lifetime, e.g. 2 h, 31 d.
     *
     * @param  int  $minutes
     * @return string
     */
    public static function duration(int $minutes): string
    {
        if ($minutes % 1440 === 0 || $minutes >= 2 * 1440) {
            return round($minutes / 1440).' d';
        }

        if ($minutes % 60 === 0 || $minutes >= 120) {
            return round($minutes / 60).' h';
        }

        return $minutes.' min';
    }

    /**
     * Append a line to the log of the runs.
     *
     * @param  string  $message
     * @return void
     */
    public static function log(string $message): void
    {
        if (! is_dir(dirname(self::LOG))) {
            @mkdir(dirname(self::LOG), 0755, true);
        }

        @file_put_contents(self::LOG, '['.date('Y-m-d H:i:s').'] '.$message."\n", FILE_APPEND);
    }
}
