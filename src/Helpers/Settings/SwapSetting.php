<?php

namespace Gogol\VpsManagerCLI\Helpers\Settings;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;

/*
 * Size of the swap by the size of RAM and of the disk. Only swap files are created or resized, swap partitions
 * are reported only.
 */
class SwapSetting extends Setting
{
    /**
     * Swap file created on a server without swap.
     */
    const PATH = '/swapfile';

    /**
     * Swap differing from the recommended size by less than this part is kept.
     */
    const TOLERANCE = 0.25;

    /**
     * Swap is at most this part of the disk.
     */
    const MAX_DISK_PART = 0.1;

    /**
     * Part of the disk which stays free.
     */
    const FREE_DISK_PART = 0.2;

    /**
     * Smaller swap is not created.
     */
    const MIN_SIZE = SystemInfo::GB;

    /**
     * Free RAM kept besides the pages loaded back from the swap when it is turned off.
     */
    const RESERVE = 256 * SystemInfo::MB;

    /**
     * Key of the setting used by --only.
     *
     * @return string
     */
    public function key(): string
    {
        return 'swap';
    }

    /**
     * Recommended size of the swap of a server without hibernation by its nominal RAM.
     *
     * @param  int  $ram
     * @return int
     */
    public static function recommendedSize(int $ram): int
    {
        $gb = SystemInfo::GB;

        return match (true) {
            $ram < 2 * $gb => 2 * $ram,
            $ram <= 8 * $gb => $ram,
            $ram <= 64 * $gb => max(intdiv($ram, 2), 4 * $gb),
            default => 4 * $gb,
        };
    }

    /**
     * Limit the size of the swap by the disk: 10 % of the disk at most and 20 % of the disk stays free. Rounded
     * down to 128 MB, 0 when less than 1 GB fits.
     *
     * @param  int  $size
     * @param  int  $diskTotal
     * @param  int  $diskFree
     * @param  int  $reclaimable  size of the swap file which the new one replaces
     * @return int
     */
    public static function limitByDisk(int $size, int $diskTotal, int $diskFree, int $reclaimable = 0): int
    {
        $available = $diskFree + $reclaimable - (int) ($diskTotal * self::FREE_DISK_PART);
        $size = min($size, (int) ($diskTotal * self::MAX_DISK_PART), $available);
        $size = intdiv(max($size, 0), 128 * SystemInfo::MB) * 128 * SystemInfo::MB;

        return $size >= self::MIN_SIZE ? $size : 0;
    }

    /**
     * Determine if the swap differs from the target enough to be changed.
     *
     * @param  int  $current
     * @param  int  $target
     * @return bool
     */
    public static function needsChange(int $current, int $target): bool
    {
        return $target > 0 && abs($current - $target) >= $target * self::TOLERANCE;
    }

    /**
     * Content of /etc/fstab with exactly one line of the swap file.
     *
     * @param  string  $fstab
     * @param  string  $path
     * @return string
     */
    public static function fstabWithSwap(string $fstab, string $path): string
    {
        $lines = $fstab === '' ? [] : explode("\n", rtrim($fstab, "\n"));
        $found = false;

        foreach ($lines as $i => $line) {
            $fields = preg_split('/\s+/', trim($line));

            if (str_starts_with(trim($line), '#') || ($fields[0] ?? '') !== $path) {
                continue;
            }

            if ($found) {
                unset($lines[$i]);
            }

            $found = true;
        }

        if (! $found) {
            $lines[] = $path.' none swap sw 0 0';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Read the swaps, RAM and the disk of the swap file.
     *
     * @return array
     */
    protected function inspect(): array
    {
        $memory = SystemInfo::memInfo();
        $swaps = SystemInfo::swaps();
        $files = array_values(array_filter($swaps, fn ($swap) => $swap['type'] === 'file'));
        $partitions = array_values(array_filter($swaps, fn ($swap) => $swap['type'] !== 'file'));
        $current = array_sum(array_column($swaps, 'size'));
        $used = array_sum(array_column($swaps, 'used'));
        $ram = SystemInfo::nominalRam($memory['MemTotal'] ?? 0);
        $path = $files[0]['path'] ?? self::PATH;
        $directory = is_dir(dirname($path)) ? dirname($path) : '/';

        // The swap file of the same disk is freed by its resize
        $reclaimable = count($files) === 1 && ! $partitions ? $files[0]['size'] : 0;
        $target = static::limitByDisk(
            static::recommendedSize($ram),
            (int) disk_total_space($directory),
            (int) disk_free_space($directory),
            $reclaimable,
        );

        $state = [
            'current' => $current,
            'used' => $used,
            'available' => $memory['MemAvailable'] ?? 0,
            'recommended' => static::recommendedSize($ram),
            'target' => $target,
            'path' => $path,
            'action' => null,
            'fstab' => false,
            'warnings' => [],
        ];

        if ($target === 0) {
            $state['warnings'][] = 'The disk has no room for 1 GB of swap (10 % of the disk at most, 20 % stays free), no swap is created.';

            return $state;
        }

        if ($partitions) {
            if (static::needsChange($current, $target)) {
                $state['warnings'][] = 'Swap partition '.implode(', ', array_column($partitions, 'path')).' is never changed, the recommended size is '.static::size($target).'.';
            }

            return $state;
        }

        if (count($files) > 1) {
            if (static::needsChange($current, $target)) {
                $state['warnings'][] = 'Several swap files ('.implode(', ', array_column($files, 'path')).') are not changed, the recommended size is '.static::size($target).'.';
            }

            return $state;
        }

        if (! $files) {
            $state['action'] = 'create';
        } elseif (static::needsChange($current, $target)) {
            if ($state['available'] > $used + self::RESERVE) {
                $state['action'] = 'resize';
            } elseif ($target > $current) {
                // Turning off the swap would need more free RAM, a second swap file adds the missing size
                $state['action'] = 'add';
                $state['path'] = $this->nextPath();
                $state['target'] = intdiv($target - $current, 128 * SystemInfo::MB) * 128 * SystemInfo::MB;
            } else {
                $state['warnings'][] = 'Swap cannot be made smaller now: '.static::size($used).' of the swap would not fit into '.static::size($state['available']).' of free RAM.';
            }
        }

        if ($state['action'] !== null || $files) {
            $state['fstab'] = static::fstabWithSwap($this->fstab(), $state['path']) !== $this->fstab();
        }

        return $state;
    }

    /**
     * Current value of the setting for the table.
     *
     * @return string
     */
    public function current(): string
    {
        $state = $this->state();

        if ($state['current'] === 0) {
            return 'none';
        }

        return static::size($state['current']).' ('.static::size($state['used']).' used)';
    }

    /**
     * Recommended value of the setting for the table.
     *
     * @return string
     */
    public function recommended(): string
    {
        $state = $this->state();

        if ($state['action'] === 'add') {
            return static::size($state['current'] + $state['target']);
        }

        return $state['target'] ? static::size($state['target']) : 'none';
    }

    /**
     * Changes which would be made.
     *
     * @return array
     */
    public function plan(): array
    {
        $state = $this->state();

        $plan = match ($state['action']) {
            'create' => ['create '.$state['path']],
            'resize' => ['resize '.$state['path']],
            'add' => ['add '.$state['path'].' of '.static::size($state['target'])],
            default => [],
        };

        if ($state['fstab']) {
            $plan[] = 'add to /etc/fstab';
        }

        return $plan;
    }

    /**
     * Create, resize or add the swap file and add it to /etc/fstab.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Closure  $confirm
     * @return bool
     */
    public function apply(OutputInterface $output, Closure $confirm): bool
    {
        $state = $this->state();
        $path = $state['path'];

        if ($state['action'] === 'resize') {
            $memory = SystemInfo::memInfo();
            $used = array_sum(array_column(SystemInfo::swaps(), 'used'));

            if (($memory['MemAvailable'] ?? 0) <= $used + self::RESERVE) {
                $output->writeln('<error>Swap is not turned off: '.static::size($used).' of the swap would not fit into free RAM.</error>');

                return false;
            }

            $output->writeln('Turning off <info>'.$path.'</info>, '.static::size($used).' is loaded back into RAM…');

            if (! $this->run('swapoff '.escapeshellarg($path), $lines)) {
                $output->writeln('<error>swapoff failed: '.implode(' ', $lines).'</error>');

                return false;
            }
        }

        if ($state['action'] !== null) {
            $output->writeln('Creating <info>'.$path.'</info> of '.static::size($state['target']).'…');

            if (! $this->create($path, $state['target'], $output)) {
                // The previous swap file is turned on again
                if ($state['action'] === 'resize') {
                    $this->create($path, $state['current'], $output);
                }

                return false;
            }
        }

        if ($state['fstab']) {
            $this->write('/etc/fstab', static::fstabWithSwap($this->fstab(), $path), $output);
        }

        $swap = array_values(array_filter(SystemInfo::swaps(), fn ($swap) => $swap['path'] === $path))[0] ?? null;

        if (! $swap) {
            $output->writeln('<error>'.$path.' is not active.</error>');

            return false;
        }

        $output->writeln('Swap <info>'.$path.'</info> of '.static::size($swap['size']).' is active.');

        return true;
    }

    /**
     * Create and turn on the swap file: fallocate on ext4, dd on btrfs, xfs or when fallocate fails.
     *
     * @param  string  $path
     * @param  int  $size
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return bool
     */
    protected function create(string $path, int $size, OutputInterface $output): bool
    {
        $file = escapeshellarg($path);
        $megabytes = intdiv($size, SystemInfo::MB);
        $filesystem = SystemInfo::filesystemType(dirname($path));

        @unlink($path);
        touch($path);
        chmod($path, 0600);

        // Btrfs needs a file without copy on write
        if ($filesystem === 'btrfs') {
            $this->run('chattr +C '.$file);
        }

        $created = ! in_array($filesystem, ['btrfs', 'xfs']) && $this->run('fallocate -l '.$megabytes.'M '.$file);

        if (! $created) {
            $created = $this->run('dd if=/dev/zero of='.$file.' bs=1M count='.$megabytes.' status=none', $lines);
        }

        if (! $created || ! $this->run('mkswap '.$file, $lines) || ! $this->run('swapon '.$file, $lines)) {
            $output->writeln('<error>'.$path.' could not be created: '.implode(' ', $lines ?? []).'</error>');

            return false;
        }

        return true;
    }

    /**
     * Content of /etc/fstab.
     *
     * @return string
     */
    protected function fstab(): string
    {
        return (string) @file_get_contents('/etc/fstab');
    }

    /**
     * First free path of an additional swap file: /swapfile2, /swapfile3….
     *
     * @return string
     */
    protected function nextPath(): string
    {
        $i = 2;

        while (file_exists(self::PATH.$i)) {
            $i++;
        }

        return self::PATH.$i;
    }
}
