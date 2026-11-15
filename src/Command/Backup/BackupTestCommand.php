<?php

namespace Gogol\VpsManagerCLI\Command\Backup;

use Gogol\VpsManagerCLI\Helpers\Restic;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BackupTestCommand extends Command
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('backup:test')->setDescription('Test the connection to the restic repository of the backups');
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

        $backup = vpsManager()->backup();

        if (! $backup->isConfigured()) {
            $output->writeln('<error>Backups are not configured, run "php vpsmanager backup:setup".</error>');

            return Command::FAILURE;
        }

        $output->writeln('Repository: <comment>'.Restic::repository($backup->config()).'</comment>');

        if (($result = $backup->testRepository()) !== true) {
            $output->writeln('<error>'.$result.'</error>');

            return Command::FAILURE;
        }

        $snapshots = $backup->restic()->snapshots() ?? [];

        $output->writeln('<info>Connection has been successfully established.</info> Snapshots: <comment>'.count($snapshots).'</comment>');

        return Command::SUCCESS;
    }
}
