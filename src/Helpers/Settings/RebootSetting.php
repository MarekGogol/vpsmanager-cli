<?php

namespace Gogol\VpsManagerCLI\Helpers\Settings;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;

/*
 * A server which waits for a reboot after the updates (/var/run/reboot-required), e.g. of the kernel. It is only
 * reported, setup:run never reboots the server.
 */
class RebootSetting extends Setting
{
    const FILE = '/var/run/reboot-required';

    /**
     * Key of the setting used by --only.
     *
     * @return string
     */
    public function key(): string
    {
        return 'reboot';
    }

    /**
     * Read the flag of the updates and its packages.
     *
     * @return array
     */
    protected function inspect(): array
    {
        if (! is_file(self::FILE)) {
            return ['required' => false];
        }

        $packages = array_values(array_unique(file(self::FILE.'.pkgs', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []));

        return [
            'required' => true,
            'since' => date('Y-m-d', (int) filemtime(self::FILE)),
            'packages' => $packages,
            'warnings' => ['The server waits for a reboot since '.date('Y-m-d', (int) filemtime(self::FILE))
                .($packages ? ' ('.implode(', ', array_slice($packages, 0, 5)).(count($packages) > 5 ? '…' : '').')' : '')
                .', reboot it by hand in a quiet hour.'],
        ];
    }

    /**
     * Current value of the setting for the table.
     *
     * @return string
     */
    public function current(): string
    {
        return $this->state()['required'] ? 'required since '.$this->state()['since'] : 'not required';
    }

    /**
     * Recommended value of the setting for the table.
     *
     * @return string
     */
    public function recommended(): string
    {
        return 'not required';
    }

    /**
     * Nothing is changed, the server is rebooted by hand.
     *
     * @return array
     */
    public function plan(): array
    {
        return [];
    }

    /**
     * Nothing is applied.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Closure  $confirm
     * @return bool
     */
    public function apply(OutputInterface $output, Closure $confirm): bool
    {
        return true;
    }
}
