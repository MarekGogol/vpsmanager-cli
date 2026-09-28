<?php

namespace Gogol\VpsManagerCLI\Command\Backup;

use Exception;
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
            ->setDescription('Backup all databases, websites data, and other files. Also copies data to other server.')
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

        if (! vpsManager()->config('backup_path')) {
            throw new Exception('Please, first start backups configuration with "php vpsmanager backup:setup" command.');
        }

        $missing = vpsManager()->backup()->checkRequirements();

        if (count($missing) > 0) {
            throw new Exception('Please, first install missing extensions "apt install -y '.implode(' ', $missing).'"');
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
