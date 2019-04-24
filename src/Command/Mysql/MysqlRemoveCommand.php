<?php

namespace Gogol\VpsManagerCLI\Command\Mysql;

use Gogol\VpsManagerCLI\Nginx\Nginx;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class MysqlRemoveCommand extends Command
{
    private $input;
    private $output;
    private $helper;

    protected function configure()
    {
        $this->setName('mysql:remove')
             ->addArgument('name', InputArgument::OPTIONAL, 'Database/User name')
             ->addOption('name', null, InputOption::VALUE_OPTIONAL, 'Database/User name', null)
             ->setDescription('Removes mysql user/database');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->helper = $this->getHelper('question');

        vpsManager()->bootConsole($output, $input, $this->helper);

        $db = $this->getDBName();
        $output->writeln('');

        $response = vpsManager()->mysql()->removeDatabaseWithUser($db);

        $this->output->writeln('<info>'.$response->message.'</info>');
    }

    public function getDBName()
    {
        if ( ($name = $this->input->getArgument('name')) )
        {
            if ( ! vpsManager()->mysql()->isValidDBName($name) )
                $this->output->writeln('<error>Please fill valid database name.</error>');
            else
                return $name;
        }

        $question = new Question('<info>Please fill database/user name what you want delete (eg.</info> my_db<info>):</info> ', $this->input->getOption('name'));
        $question->setValidator(function($host) {
            if ( ! $host || ! vpsManager()->mysql()->isValidDBName($host) )
                throw new \Exception('Please fill valid database/user name.');

            return $host;
        });

        return $this->helper->ask($this->input, $this->output, $question);
    }
}