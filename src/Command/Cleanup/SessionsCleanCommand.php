<?php

namespace Gogol\VpsManagerCLI\Command\Cleanup;

use Gogol\VpsManagerCLI\Helpers\Sessions;
use Gogol\VpsManagerCLI\Helpers\Settings\Setting;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SessionsCleanCommand extends Command
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('sessions:clean')
            ->setDescription('Remove the file sessions of the Laravel applications older than their lifetime from config/session.php, at least '.(Sessions::MIN_MINUTES / 60).' hours old')
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

        if (! is_dir(dirname(Sessions::LOCK))) {
            mkdir(dirname(Sessions::LOCK), 0700, true);
        }

        $lock = fopen(Sessions::LOCK, 'c');

        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $output->writeln('<comment>sessions:clean is running already.</comment>');

            return Command::SUCCESS;
        }

        // Removing of many files must not slow down the hostings
        proc_nice(19);
        exec('ionice -c3 -p '.getmypid().' 2> /dev/null');

        $www = vpsManager()->config('www_path', '/var/www');
        $rows = [];
        $logged = [];
        $removed = $bytes = $failed = 0;

        foreach (Sessions::apps($www) as $app) {
            $name = substr($app, strlen(rtrim($www, '/')) + 1);
            $plan = Sessions::plan($app, Sessions::readConfig($app));

            if (isset($plan['skip'])) {
                $rows[] = [$name, '<comment>skipped: '.$plan['skip'].'</comment>', '', '', ''];

                continue;
            }

            $result = Sessions::clean($plan['directory'], $plan['after'], $dryRun);
            $removed += $result['removed'];
            $bytes += $result['bytes'];
            $failed += $result['failed'];

            $rows[] = [
                $name,
                Sessions::duration($plan['lifetime']).($plan['after'] > $plan['lifetime'] ? ' (removed after '.Sessions::duration($plan['after']).')' : ''),
                number_format($result['files'], 0, '.', ' '),
                number_format($result['removed'], 0, '.', ' ').($result['failed'] ? ' <error>('.$result['failed'].' failed)</error>' : ''),
                $result['removed'] ? Setting::size($result['bytes']) : '',
            ];

            if ($result['removed'] || $result['failed']) {
                $logged[] = $name.' '.$result['removed'].($result['failed'] ? ' (+'.$result['failed'].' failed)' : '').' of '.$result['files'];
            }
        }

        if (! $rows) {
            $output->writeln('No Laravel applications in '.$www.'.');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Application', 'Lifetime', 'Sessions', $dryRun ? 'Would remove' : 'Removed', 'Disk']);
        $table->setRows($rows);
        $table->render();

        $summary = ($dryRun ? 'Would remove ' : 'Removed ').number_format($removed, 0, '.', ' ').' sessions, '.Setting::size($bytes);
        $output->writeln($summary.'.');

        if (! $dryRun) {
            Sessions::log($summary.($logged ? ': '.implode(', ', $logged) : ''));
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
