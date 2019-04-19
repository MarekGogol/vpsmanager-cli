<?php

namespace Gogol\VpsManagerCLI\Command;

use Gogol\VpsManagerCLI\Nginx\Nginx;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class BackupRemoteTestServer extends Command
{
    private $input;
    private $output;

    protected function configure()
    {
        $this->setName('backup:remote-test')
             ->setDescription('Test remote server connection');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        vpsManager()->bootConsole($output);

        $this->input = $input;
        $this->output = $output;
        $this->helper = $helper = $this->getHelper('question');

        $output->writeln('');

        $this->testRemoteServer();
    }

    private function testRemoteServer()
    {
        $b = vpsManager()->backup();

        if ( $b->testRemoteServer() )
            $this->output->writeln('<info>Connection has been successfully established.</info>');
        else {
            $this->output->writeln('<error>Could not connect to remote server.</error>');
            $this->output->writeln('<info>You can test command mannualy:</info> ssh '.$b->config('remote_user').'@'.$b->config('remote_server').' -i '.$b->getRemoteRSAKeyPath());
        }
    }
}