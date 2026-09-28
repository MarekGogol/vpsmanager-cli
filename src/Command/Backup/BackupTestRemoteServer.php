<?php

namespace Gogol\VpsManagerCLI\Command\Backup;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BackupTestRemoteServer extends Command
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('backup:test-remote')->setDescription('Test remote server connection');
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

        $output->writeln('');

        $backup = vpsManager()->backup();

        if ($backup->testRemoteServer()) {
            $output->writeln('<info>Connection has been successfully established.</info>');

            return Command::SUCCESS;
        }

        $output->writeln('<error>Could not connect to remote server.</error>');
        $output->writeln(
            '<info>You can test the command manually, maybe you just need to accept the server key:</info>'."\n".
            '<comment>ssh '.$backup->config('remote_user').'@'.$backup->config('remote_server').' -i '.$backup->getRemoteRSAKeyPath().'</comment>',
        );

        return Command::FAILURE;
    }
}
