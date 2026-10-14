<?php

namespace Gogol\VpsManagerCLI\Helpers\Settings;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;

/*
 * Cron of swap:refresh, every night at 3:00: before the backups at 4:00 and the synchronization of the orders
 * at 5:00.
 */
class SwapRefreshSetting extends Setting
{
    /**
     * Key of the setting used by --only.
     *
     * @return string
     */
    public function key(): string
    {
        return 'swap-refresh';
    }

    /**
     * Read the cron.
     *
     * @return array
     */
    protected function inspect(): array
    {
        return [
            'exists' => is_file(SwapRefresh::CRON),
            'written' => is_file(SwapRefresh::CRON) && file_get_contents(SwapRefresh::CRON) === SwapRefresh::cron(),
        ];
    }

    /**
     * Current value of the setting for the table.
     *
     * @return string
     */
    public function current(): string
    {
        return $this->state()['exists'] ? 'cron' : 'none';
    }

    /**
     * Recommended value of the setting for the table.
     *
     * @return string
     */
    public function recommended(): string
    {
        return 'cron at 3:00';
    }

    /**
     * Changes which would be made.
     *
     * @return array
     */
    public function plan(): array
    {
        return $this->state()['written'] ? [] : ['write '.SwapRefresh::CRON];
    }

    /**
     * Write the cron.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Closure  $confirm
     * @return bool
     */
    public function apply(OutputInterface $output, Closure $confirm): bool
    {
        return $this->write(SwapRefresh::CRON, SwapRefresh::cron(), $output);
    }
}
