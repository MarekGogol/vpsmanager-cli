<?php

namespace Gogol\VpsManagerCLI\Helpers\Settings;

use Gogol\VpsManagerCLI\Helpers\Backup;

/*
 * Refresh of the swap by hand (swap:refresh): Linux never empties the swap by itself, pages come back only when
 * they are needed. Turning the swaps of /etc/fstab off and on loads them back into RAM. It is no fix of a server
 * short of memory, setup:run sizes MySQL and PHP-FPM instead.
 */
class SwapRefresh
{
    const LOG = '/var/log/vpsmanager/swap-refresh.log';

    const LOCK = '/var/lib/vpsmanager/swap-refresh.lock';

    /**
     * Smaller used swap is not refreshed.
     */
    const MIN_USED = 64 * SystemInfo::MB;

    /**
     * Part of RAM which stays free after the swap is loaded back.
     */
    const FREE_PART = 0.25;

    /**
     * Reason why the swap is not refreshed, null when it may be.
     *
     * @param  int  $memTotal
     * @param  int  $memAvailable
     * @param  int  $swapUsed
     * @return string|null
     */
    public static function reasonToSkip(int $memTotal, int $memAvailable, int $swapUsed): ?string
    {
        if ($swapUsed <= self::MIN_USED) {
            return 'only '.Setting::size($swapUsed).' in the swap';
        }

        if ($memAvailable - $swapUsed <= $memTotal * self::FREE_PART) {
            return Setting::size($swapUsed).' of the swap would leave less than 25 % of RAM free ('.Setting::size($memAvailable).' available)';
        }

        return null;
    }

    /**
     * Active swaps listed in /etc/fstab, the others are not touched.
     *
     * @param  array  $swaps  of SystemInfo::swaps()
     * @param  string  $fstab
     * @return array paths
     */
    public static function fstabSwaps(array $swaps, string $fstab): array
    {
        $listed = [];

        foreach (explode("\n", $fstab) as $line) {
            $fields = preg_split('/\s+/', trim($line));

            if (count($fields) >= 3 && ! str_starts_with($fields[0], '#') && $fields[2] === 'swap') {
                $listed[] = static::device($fields[0]);
            }
        }

        return array_values(array_filter(
            array_column($swaps, 'path'),
            fn ($path) => in_array($path, $listed, true) || in_array(static::device($path), $listed, true),
        ));
    }

    /**
     * Device of a line of fstab: UUID= and LABEL= are resolved, other paths are kept.
     *
     * @param  string  $source
     * @return string
     */
    protected static function device(string $source): string
    {
        if (preg_match('/^(UUID|LABEL|PARTUUID)=(.+)$/', $source, $match)) {
            $link = '/dev/disk/by-'.strtolower($match[1]).'/'.$match[2];

            return realpath($link) ?: $source;
        }

        return realpath($source) ?: $source;
    }

    /**
     * Determine if backup:run is running.
     *
     * @return bool
     */
    public static function isBackupRunning(): bool
    {
        if (! file_exists(Backup::LOCK) || ! $lock = fopen(Backup::LOCK, 'r')) {
            return false;
        }

        $free = flock($lock, LOCK_SH | LOCK_NB);

        if ($free) {
            flock($lock, LOCK_UN);
        }

        fclose($lock);

        return ! $free;
    }

    /**
     * Append a line to the log.
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
