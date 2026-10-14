<?php

namespace Gogol\VpsManagerCLI\Command\Settings;

use Gogol\VpsManagerCLI\Helpers\Settings\Setting;
use Gogol\VpsManagerCLI\Helpers\Settings\SwapRefresh;
use Gogol\VpsManagerCLI\Helpers\Settings\SystemInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SwapRefreshCommand extends Command
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('swap:refresh')
            ->setDescription('Load the swap back into RAM when enough RAM is free (swapoff and swapon of the swaps of /etc/fstab), run by cron at 3:00')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only print whether the swap would be refreshed');
    }

    /**
     * Execute the command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        vpsManager()->bootConsole($output, $input, $this->getHelper('question'));

        $dryRun = (bool) $input->getOption('dry-run');

        if (! is_dir(dirname(SwapRefresh::LOCK))) {
            mkdir(dirname(SwapRefresh::LOCK), 0700, true);
        }

        $lock = fopen(SwapRefresh::LOCK, 'c');

        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $output->writeln('<comment>swap:refresh is running already.</comment>');

            return Command::SUCCESS;
        }

        if (SwapRefresh::isBackupRunning()) {
            return $this->skip($output, 'backup:run is running', $dryRun);
        }

        $memory = SystemInfo::memInfo();
        $before = ($memory['SwapTotal'] ?? 0) - ($memory['SwapFree'] ?? 0);

        if ($reason = SwapRefresh::reasonToSkip($memory['MemTotal'] ?? 0, $memory['MemAvailable'] ?? 0, $before)) {
            return $this->skip($output, $reason, $dryRun);
        }

        $paths = SwapRefresh::fstabSwaps(SystemInfo::swaps(), (string) @file_get_contents('/etc/fstab'));

        if (! $paths) {
            return $this->skip($output, 'no active swap is listed in /etc/fstab', $dryRun);
        }

        if ($dryRun) {
            $output->writeln('Would refresh '.implode(', ', $paths).': '.Setting::size($before).' of the swap, '.Setting::size($memory['MemAvailable']).' of RAM available.');

            return Command::SUCCESS;
        }

        // Loading the swap must not slow down the other processes
        proc_nice(19);
        exec('ionice -c3 -p '.getmypid().' 2> /dev/null');

        $start = microtime(true);
        $errors = [];

        foreach ($paths as $path) {
            $lines = [];
            exec('swapoff '.escapeshellarg($path).' 2>&1', $lines, $return_var);

            if ($return_var !== 0) {
                $errors[] = 'swapoff '.$path.': '.implode(' ', $lines);
            }

            // Turned on again even after a failed swapoff
            $lines = [];
            exec('swapon '.escapeshellarg($path).' 2>&1', $lines, $return_var);

            if ($return_var !== 0 && ! in_array($path, array_column(SystemInfo::swaps(), 'path'), true)) {
                $errors[] = 'swapon '.$path.': '.implode(' ', $lines);
            }
        }

        $memory = SystemInfo::memInfo();
        $after = ($memory['SwapTotal'] ?? 0) - ($memory['SwapFree'] ?? 0);
        $message = 'swap '.Setting::size($before).' -> '.Setting::size($after).' in '.round(microtime(true) - $start, 1).' s';

        if ($errors) {
            SwapRefresh::log('failed: '.$message.', '.implode(', ', $errors));
            $output->writeln('<error>Failed: '.$message.', '.implode(', ', $errors).'</error>');

            return Command::FAILURE;
        }

        SwapRefresh::log('refreshed: '.$message);
        $output->writeln('Refreshed: <info>'.$message.'</info>.');

        return Command::SUCCESS;
    }

    /**
     * Skip the refresh and log its reason.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  string  $reason
     * @param  bool  $dryRun
     * @return int
     */
    protected function skip(OutputInterface $output, string $reason, bool $dryRun): int
    {
        if (! $dryRun) {
            SwapRefresh::log('skipped: '.$reason);
        }

        $output->writeln('Skipped: '.$reason.'.');

        return Command::SUCCESS;
    }
}
