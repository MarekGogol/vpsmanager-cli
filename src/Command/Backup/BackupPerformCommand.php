<?php

namespace Gogol\VpsManagerCLI\Command\Backup;

use Exception;
use Gogol\VpsManagerCLI\Helpers\Backup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackupPerformCommand extends Command
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('backup:run')
            ->setDescription('Backup all databases, websites and server directories into the restic repository')
            ->addOption('databases', null, InputOption::VALUE_OPTIONAL, 'Backup all databases', false)
            ->addOption('dirs', null, InputOption::VALUE_OPTIONAL, 'Backup all directories', false)
            ->addOption('www', null, InputOption::VALUE_OPTIONAL, 'Backup all www data', false);
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
        vpsManager()->bootConsole($output, $input, $this->getHelper('question'));

        $backup = vpsManager()->backup();

        // Servers with the old tar.gz backups stop here until backup:setup, with an email
        if (! $backup->isConfigured()) {
            throw new Exception($backup->reportError('Backups are not configured, nothing was backed up. Please, run "php vpsmanager backup:setup".'));
        }

        if (count($missing = $backup->checkRequirements()) > 0) {
            throw new Exception($backup->reportError('Backups could not run, missing requirements: '.implode(', ', $missing)));
        }

        // Held until the end of the run, swap:refresh does not run during a backup
        if (! $lock = Backup::lock()) {
            $output->writeln('<comment>backup:run or backup:maintenance is running already.</comment>');

            return Command::SUCCESS;
        }

        // If no option has been passed, everything will be backed up
        $all = $input->getOption('databases') === false && $input->getOption('dirs') === false && $input->getOption('www') === false;

        $response = vpsManager()
            ->backup()
            ->perform([
                'databases' => $all || $input->getOption('databases') === null,
                'dirs' => $all || $input->getOption('dirs') === null,
                'www' => $all || $input->getOption('www') === null,
            ]);

        if ($response->isError()) {
            throw new Exception($response->message);
        }

        $output->writeln('<info>'.$response->message.'</info>');

        return Command::SUCCESS;
    }
}
