<?php

namespace Gogol\VpsManagerCLI\Command\Cleanup;

use Gogol\VpsManagerCLI\Helpers\Caches;
use Gogol\VpsManagerCLI\Helpers\Settings\Setting;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CacheCleanCommand extends Command
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('cache:clean')
            ->setDescription('Remove the caches of npm, Cypress and Composer of the hostings, root and /home and the downloaded packages of APT, logs are kept')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only print what would be removed');
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

        if (! is_dir(dirname(Caches::LOCK))) {
            mkdir(dirname(Caches::LOCK), 0700, true);
        }

        $lock = fopen(Caches::LOCK, 'c');

        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $output->writeln('<comment>cache:clean is running already.</comment>');

            return Command::SUCCESS;
        }

        proc_nice(19);
        exec('ionice -c3 -p '.getmypid().' 2> /dev/null');

        $rows = [];
        $logged = [];
        $total = 0;
        $failed = false;

        foreach (Caches::homes(vpsManager()->config('www_path', '/var/www')) as $home) {
            if (! $paths = Caches::candidates($home)) {
                continue;
            }

            if (Caches::isBusy($home)) {
                $rows[] = [$home, '<comment>skipped: npm or composer of its user is running</comment>', ''];

                continue;
            }

            $bytes = 0;
            $errors = [];

            foreach ($paths as $path) {
                $size = Caches::size($path);

                if ($dryRun || Caches::remove($path)) {
                    $bytes += $size;
                } else {
                    $errors[] = $path;
                }
            }

            $total += $bytes;
            $failed = $failed || $errors;

            $names = array_unique(array_map(fn ($path) => $this->target($home, $path), $paths));
            $rows[] = [$home, implode(', ', $names).($errors ? ' <error>(failed: '.implode(', ', $errors).')</error>' : ''), Setting::size($bytes)];

            if ($bytes) {
                $logged[] = $home.' '.Setting::size($bytes);
            }
        }

        $apt = Caches::aptSize();

        if ($apt > 0) {
            if ($dryRun || $this->aptClean()) {
                $total += $apt;
                $rows[] = ['/var/cache/apt/archives', 'apt-get clean', Setting::size($apt)];
                $logged[] = 'apt '.Setting::size($apt);
            } else {
                $failed = true;
                $rows[] = ['/var/cache/apt/archives', '<error>apt-get clean failed</error>', ''];
            }
        }

        if (! $rows) {
            $output->writeln('No caches to remove.');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Home', 'Caches', $dryRun ? 'Would free' : 'Freed']);
        $table->setRows($rows);
        $table->render();

        $summary = ($dryRun ? 'Would free ' : 'Freed ').Setting::size($total);
        $output->writeln($summary.'.');

        if (! $dryRun) {
            Caches::log($summary.($logged ? ': '.implode(', ', $logged) : ''));
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Cache of the home which contains the path, e.g. .npm/_npx.
     *
     * @param  string  $home
     * @param  string  $path
     * @return string
     */
    protected function target(string $home, string $path): string
    {
        $relative = substr($path, strlen(rtrim($home, '/')) + 1);

        foreach (array_keys(Caches::TARGETS) as $target) {
            if ($relative === $target || str_starts_with($relative, $target.'/')) {
                return $target;
            }
        }

        return $relative;
    }

    /**
     * Remove the downloaded packages of APT.
     *
     * @return bool
     */
    protected function aptClean(): bool
    {
        exec('apt-get clean 2>&1', $lines, $code);

        return $code === 0;
    }
}
