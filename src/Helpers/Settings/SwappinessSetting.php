<?php

namespace Gogol\VpsManagerCLI\Helpers\Settings;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;

/*
 * vm.swappiness of a server with the web, PHP and MySQL together: the kernel frees the cache of the files rather
 * than moving the memory of the processes into the swap. 0 is not used, it leads to the OOM killer sooner.
 */
class SwappinessSetting extends Setting
{
    const FILE = '/etc/sysctl.d/60-vpsmanager.conf';

    const VALUE = 10;

    /**
     * Key of the setting used by --only.
     *
     * @return string
     */
    public function key(): string
    {
        return 'swappiness';
    }

    /**
     * Content of the managed sysctl file.
     *
     * @return string
     */
    public function content(): string
    {
        return $this->header("Kernel settings of a server with the web, PHP and MySQL together.\n"
            .'vm.swappiness: the kernel frees the cache of the files rather than moving the memory of the processes into the swap.')
            .'vm.swappiness = '.self::VALUE."\n";
    }

    /**
     * Comment out the lines of the sysctl key, e.g. vm.swappiness or vm/swappiness.
     *
     * @param  string  $content
     * @param  string  $key
     * @param  string  $note
     * @return string
     */
    public static function disableKey(string $content, string $key, string $note): string
    {
        $pattern = '/^\s*-?\s*'.str_replace('\.', '[.\/]', preg_quote($key, '/')).'\s*=.*$/m';

        return preg_replace_callback($pattern, fn ($match) => '# '.$note.': '.trim($match[0]), $content);
    }

    /**
     * Other sysctl files of /etc which set vm.swappiness, /etc/sysctl.conf is applied after sysctl.d.
     *
     * @return array path => content without the key
     */
    protected function conflicts(): array
    {
        $conflicts = [];

        foreach (array_merge(['/etc/sysctl.conf'], glob('/etc/sysctl.d/*.conf') ?: []) as $path) {
            if ($path === self::FILE || ! is_file($path) || is_link($path) && realpath($path) === realpath(self::FILE)) {
                continue;
            }

            $content = (string) file_get_contents($path);
            $disabled = static::disableKey($content, 'vm.swappiness', 'Disabled by VPS Manager (setup:run), see '.self::FILE);

            if ($disabled !== $content) {
                $conflicts[$path] = $disabled;
            }
        }

        return $conflicts;
    }

    /**
     * Read vm.swappiness and the sysctl files.
     *
     * @return array
     */
    protected function inspect(): array
    {
        return [
            'value' => (int) trim((string) @file_get_contents('/proc/sys/vm/swappiness')),
            'written' => is_file(self::FILE) && file_get_contents(self::FILE) === $this->content(),
            'conflicts' => $this->conflicts(),
        ];
    }

    /**
     * Current value of the setting for the table.
     *
     * @return string
     */
    public function current(): string
    {
        return (string) $this->state()['value'];
    }

    /**
     * Recommended value of the setting for the table.
     *
     * @return string
     */
    public function recommended(): string
    {
        return (string) self::VALUE;
    }

    /**
     * Changes which would be made.
     *
     * @return array
     */
    public function plan(): array
    {
        $state = $this->state();
        $plan = [];

        if (! $state['written']) {
            $plan[] = 'write '.self::FILE;
        }

        if ($state['conflicts']) {
            $plan[] = 'comment out vm.swappiness in '.implode(', ', array_keys($state['conflicts']));
        }

        if ($plan || $state['value'] !== self::VALUE) {
            $plan[] = 'apply now';
        }

        return $plan;
    }

    /**
     * Write the sysctl file and apply it without a restart.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Closure  $confirm
     * @return bool
     */
    public function apply(OutputInterface $output, Closure $confirm): bool
    {
        foreach ($this->state()['conflicts'] as $path => $content) {
            if (! $this->write($path, $content, $output)) {
                return false;
            }
        }

        if (! $this->write(self::FILE, $this->content(), $output)) {
            return false;
        }

        if (! $this->run('sysctl -p '.escapeshellarg(self::FILE), $lines)) {
            $output->writeln('<error>sysctl failed: '.implode(' ', $lines).'</error>');

            return false;
        }

        $value = (int) trim((string) shell_exec('sysctl -n vm.swappiness'));

        if ($value !== self::VALUE) {
            $output->writeln('<error>vm.swappiness is '.$value.' instead of '.self::VALUE.'.</error>');

            return false;
        }

        $output->writeln('vm.swappiness is <info>'.$value.'</info>.');

        return true;
    }
}
