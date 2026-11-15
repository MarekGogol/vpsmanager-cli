<?php

namespace Gogol\VpsManagerCLI\Command\Backup;

use Exception;
use Gogol\VpsManagerCLI\Helpers\Backup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BackupMaintenanceCommand extends Command
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('backup:maintenance')->setDescription('Remove unused data of old backups (prune) and check the repository');
    }

    /**
     * Execute the command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return int
     *
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        vpsManager()->bootConsole($output);

        $backup = vpsManager()->backup();

        if (! $backup->isConfigured()) {
            throw new Exception('Backups are not configured, run "php vpsmanager backup:setup".');
        }

        // Prune needs the repository alone, it waits for a running backup
        if (! $lock = Backup::lock(true)) {
            throw new Exception('The lock of the backups could not be acquired.');
        }

        // Taken after the lock of the backups, so it is held only while the maintenance really runs
        $maintenanceLock = Backup::lockMaintenance();

        $response = $backup->maintenance();

        if ($response->isError()) {
            throw new Exception($response->message);
        }

        $output->writeln('<info>'.$response->message.'</info>');

        return Command::SUCCESS;
    }
}
