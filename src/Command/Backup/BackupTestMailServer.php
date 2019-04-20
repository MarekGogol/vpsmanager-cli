<?php

namespace Gogol\VpsManagerCLI\Command\Backup;

use Gogol\VpsManagerCLI\Nginx\Nginx;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class BackupTestMailServer extends Command
{
    private $input;
    private $output;

    protected function configure()
    {
        $this->setName('backup:test-mail')
             ->setDescription('Test mailserver connection and send test email');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        vpsManager()->bootConsole($output);

        $this->input = $input;
        $this->output = $output;
        $this->helper = $helper = $this->getHelper('question');

        $output->writeln('');

        $this->testMailServer();
    }

    private function testMailServer()
    {
        $b = vpsManager()->backup();

        if ( ($error = $b->testMailServer()) === true )
            $this->output->writeln('<info>Test email has been successfully sent.</info>');
        else {
            $this->output->writeln('<info>Test message could not be sent. Mailer Error:</info>');
            $this->output->writeln('<error>'.$error.'</error>');
        }
    }
}