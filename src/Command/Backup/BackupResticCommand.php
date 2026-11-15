<?php

namespace Gogol\VpsManagerCLI\Command\Backup;

use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BackupResticCommand extends Command
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('backup:restic')
            ->setDescription('Run a restic command with the repository and the password of the backups, e.g. backup:restic -- snapshots')
            ->addArgument('arguments', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Arguments of restic, put -- before options of restic');
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

        if (! vpsManager()->backup()->isConfigured()) {
            throw new Exception('Backups are not configured, run "php vpsmanager backup:setup".');
        }

        return vpsManager()->restic()->passthru($input->getArgument('arguments'));
    }
}
