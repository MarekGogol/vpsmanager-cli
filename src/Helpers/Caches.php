<?php

namespace Gogol\VpsManagerCLI\Helpers;

/*
 * Caches of npm, Cypress and Composer in the homes of the hostings, root and /home (cache:clean). They are
 * downloaded again by the next install, e.g. two copies of npx mint took 2 GB on crudadmin.com and Cypress
 * 800 MB on autbill.com and korfs.sk. Logs are never removed.
 */
class Caches
{
    /**
     * Caches in a home: path => what is removed. 'all' removes the whole directory, 'old' only its entries not
     * changed for MAX_AGE_DAYS (an npx package, a version of Cypress).
     */
    const TARGETS = [
        '.npm/_cacache' => 'all',
        '.npm/_npx' => 'old',
        '.cache/Cypress' => 'old',
        '.cache/composer' => 'all',
        '.composer/cache' => 'all',
    ];

    /**
     * Entries of the 'old' caches changed earlier are removed.
     */
    const MAX_AGE_DAYS = 30;

    /**
     * Processes of a user which use the caches, the home is skipped while one runs. Not node, applications
     * of pm2 run all the time.
     */
    const BUSY = '(^|/)(npm|npx|composer|cypress)( |$)';

    /**
     * Log of the runs.
     */
    const LOG = '/var/log/vpsmanager/cache-clean.log';

    /**
     * Lock of a running cache:clean.
     */
    const LOCK = '/var/lib/vpsmanager/cache-clean.lock';

    /**
     * Homes with caches: data of the hostings, root and the users of /home.
     *
     * @param  string  $www
     * @return array
     */
    public static function homes(string $www): array
    {
        $homes = array_merge(glob(rtrim($www, '/').'/*/data', GLOB_ONLYDIR) ?: [], ['/root'], glob('/home/*', GLOB_ONLYDIR) ?: []);

        return array_values(array_filter($homes, fn ($home) => is_dir($home) && ! is_link($home)));
    }

    /**
     * Paths to remove in the home.
     *
     * @param  string  $home
     * @param  int|null  $now
     * @return array
     */
    public static function candidates(string $home, ?int $now = null): array
    {
        $cutoff = ($now ?? time()) - self::MAX_AGE_DAYS * 86400;
        $paths = [];

        foreach (self::TARGETS as $target => $mode) {
            $path = rtrim($home, '/').'/'.$target;

            // A link could point anywhere
            if (is_link($path) || ! is_dir($path)) {
                continue;
            }

            if ($mode === 'all') {
                $paths[] = $path;

                continue;
            }

            foreach (scandir($path) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $stat = @lstat($path.'/'.$name);

                if ($stat !== false && $stat['mtime'] < $cutoff) {
                    $paths[] = $path.'/'.$name;
                }
            }
        }

        return $paths;
    }

    /**
     * Determine if a process of the owner of the home uses the caches now.
     *
     * @param  string  $home
     * @return bool
     */
    public static function isBusy(string $home): bool
    {
        $owner = @fileowner($home);

        if ($owner === false) {
            return false;
        }

        exec('pgrep -u '.(int) $owner.' -f '.escapeshellarg(self::BUSY).' 2> /dev/null', $lines, $code);

        return $code === 0;
    }

    /**
     * Disk space of the path in bytes.
     *
     * @param  string  $path
     * @return int
     */
    public static function size(string $path): int
    {
        $line = (string) shell_exec('du -skx '.escapeshellarg($path).' 2> /dev/null');

        return (int) strtok($line, "\t") * 1024;
    }

    /**
     * Remove the path, links are removed without being followed and other file systems are kept.
     *
     * @param  string  $path
     * @return bool
     */
    public static function remove(string $path): bool
    {
        $stat = @lstat($path);

        return $stat === false || static::removeTree($path, $stat['dev']);
    }

    /**
     * Remove the path and its content on the given device.
     *
     * @param  string  $path
     * @param  int  $device
     * @return bool
     */
    protected static function removeTree(string $path, int $device): bool
    {
        $stat = @lstat($path);

        if ($stat === false) {
            return true;
        }

        // A directory (not a link to it) is emptied first
        if (($stat['mode'] & 0170000) !== 0040000) {
            return @unlink($path);
        }

        // A mounted file system is kept with its directory
        if ($stat['dev'] !== $device) {
            return false;
        }

        $removed = true;

        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $removed = static::removeTree($path.'/'.$name, $device) && $removed;
            }
        }

        return @rmdir($path) && $removed;
    }

    /**
     * Downloaded packages of APT in bytes.
     *
     * @return int
     */
    public static function aptSize(): int
    {
        return is_dir('/var/cache/apt/archives') ? static::size('/var/cache/apt/archives') : 0;
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
