<?php

namespace Gogol\VpsManagerCLI\Helpers\Settings;

// Memory, swap and disks of the server read from /proc, the parsing is static for the tests.
class SystemInfo
{
    const MB = 1048576;

    const GB = 1073741824;

    /**
     * Step of the nominal size of RAM: the kernel and the firmware reserve a part of RAM, a server with 2 GB has
     * MemTotal of about 1.9 GB.
     */
    const RAM_STEP = 512 * self::MB;

    /**
     * Values of /proc/meminfo in bytes.
     *
     * @return array e.g. ['MemTotal' => …, 'MemAvailable' => …, 'SwapTotal' => …, 'SwapFree' => …]
     */
    public static function memInfo(): array
    {
        return static::parseMemInfo((string) @file_get_contents('/proc/meminfo'));
    }

    /**
     * Parse /proc/meminfo into bytes.
     *
     * @param  string  $content
     * @return array
     */
    public static function parseMemInfo(string $content): array
    {
        $values = [];

        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^(\w+(?:\(\w+\))?):\s+(\d+)(?:\s+kB)?$/', trim($line), $match)) {
                $values[$match[1]] = (int) $match[2] * (str_ends_with(trim($line), 'kB') ? 1024 : 1);
            }
        }

        return $values;
    }

    /**
     * Active swaps of /proc/swaps.
     *
     * @return array list of ['path', 'type' => file|partition, 'size', 'used'] in bytes
     */
    public static function swaps(): array
    {
        return static::parseSwaps((string) @file_get_contents('/proc/swaps'));
    }

    /**
     * Parse /proc/swaps, sizes are in kB.
     *
     * @param  string  $content
     * @return array
     */
    public static function parseSwaps(string $content): array
    {
        $swaps = [];

        foreach (array_slice(explode("\n", trim($content)), 1) as $line) {
            $parts = preg_split('/\s+/', trim($line));

            if (count($parts) < 4) {
                continue;
            }

            $swaps[] = [
                // Spaces of a path are escaped as \040
                'path' => str_replace('\040', ' ', $parts[0]),
                'type' => $parts[1],
                'size' => (int) $parts[2] * 1024,
                'used' => (int) $parts[3] * 1024,
            ];
        }

        return $swaps;
    }

    /**
     * Nominal size of RAM: MemTotal rounded up to RAM_STEP, so a server with 2 GB is not taken for a smaller one.
     *
     * @param  int  $memTotal
     * @return int
     */
    public static function nominalRam(int $memTotal): int
    {
        return (int) ceil($memTotal / self::RAM_STEP) * self::RAM_STEP;
    }

    /**
     * Nominal size of RAM of this server.
     *
     * @return int
     */
    public static function ram(): int
    {
        return static::nominalRam(static::memInfo()['MemTotal'] ?? 0);
    }

    /**
     * Type of the filesystem of the path, e.g. ext4, xfs, btrfs.
     *
     * @param  string  $path
     * @return string
     */
    public static function filesystemType(string $path): string
    {
        $type = trim((string) shell_exec('findmnt -n -o FSTYPE -T '.escapeshellarg($path).' 2> /dev/null'));

        return $type ?: trim((string) shell_exec('stat -f -c %T '.escapeshellarg($path).' 2> /dev/null'));
    }
}
