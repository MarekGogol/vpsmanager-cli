<?php

namespace Gogol\VpsManagerCLI\Command;

use Gogol\VpsManagerCLI\Helpers\Cron;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CronWriteCommand extends Command
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('cron:write')
            ->setDescription('Rewrite '.Cron::PATH.' with the jobs of the monitor, the backups and the cleanup from the configuration')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the file without writing it');
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
        vpsManager()->bootConsole($output);

        $cron = vpsManager()->cron();
        $dryRun = (bool) $input->getOption('dry-run');

        $output->writeln($cron->getContent() ?? '<comment>No jobs, the file is removed.</comment>');

        foreach ($cron->write($dryRun) as $change) {
            $output->writeln('<info>'.$change.'</info>');
        }

        return Command::SUCCESS;
    }
}
