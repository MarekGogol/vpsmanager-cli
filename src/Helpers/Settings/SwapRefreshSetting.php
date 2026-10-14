<?php

namespace Gogol\VpsManagerCLI\Helpers\Settings;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;

// Cron of swap:refresh, every night in an hour without a backup of the crontab (3:30, 2:30, 4:30…).
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
        [$hour, $minute] = SwapRefresh::time(SwapRefresh::backupCronLines());
        $content = SwapRefresh::cron($hour, $minute);

        return [
            'exists' => is_file(SwapRefresh::CRON),
            'content' => $content,
            'time' => $hour.':'.sprintf('%02d', $minute),
            'written' => is_file(SwapRefresh::CRON) && file_get_contents(SwapRefresh::CRON) === $content,
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
        return 'cron at '.$this->state()['time'];
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
        return $this->write(SwapRefresh::CRON, $this->state()['content'], $output);
    }
}
