<?php

namespace Gogol\VpsManagerCLI\Command\Backup;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BackupTestMailServer extends Command
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('backup:test-mail')->setDescription('Test mail server connection and send test email');
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

        $error = vpsManager()->backup()->testMailServer();

        if ($error === true) {
            $output->writeln('<info>Test email has been successfully sent.</info>');

            return Command::SUCCESS;
        }

        $output->writeln('<info>Test message could not be sent. Mailer error:</info>');
        $output->writeln('<error>'.$error.'</error>');

        return Command::FAILURE;
    }
}
