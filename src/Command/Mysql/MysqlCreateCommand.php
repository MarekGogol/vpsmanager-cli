<?php

namespace Gogol\VpsManagerCLI\Command\Mysql;

use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class MysqlCreateCommand extends Command
{
    /**
     * The console input.
     *
     * @var \Symfony\Component\Console\Input\InputInterface
     */
    private $input;

    /**
     * The console output.
     *
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    private $output;

    /**
     * The question helper.
     *
     * @var \Symfony\Component\Console\Helper\QuestionHelper
     */
    private $helper;

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('mysql:create')
            ->addArgument('name', InputArgument::OPTIONAL, 'Database/User name')
            ->addOption('name', null, InputOption::VALUE_OPTIONAL, 'Database/User name', null)
            ->setDescription('Creates mysql user/database');
    }

    /**
     * Execute the console command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;
        $this->helper = $this->getHelper('question');

        vpsManager()->bootConsole($output, $input, $this->helper);

        $db = $this->getDBName();
        $output->writeln('');

        $response = vpsManager()
            ->mysql()
            ->createDatabase($db);

        if ($response->isError()) {
            $output->writeln('<error>'.$response->message.'</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>'.$response->message.'</info>');

        return Command::SUCCESS;
    }

    /**
     * Get the database name from the argument or ask for it.
     *
     * @return string
     */
    public function getDBName(): string
    {
        if ($name = $this->input->getArgument('name')) {
            if (vpsManager()->mysql()->isValidDBName($name)) {
                return $name;
            }

            $this->output->writeln('<error>Please enter a valid database name.</error>');
        }

        $question = new Question('<info>Please enter the database/user name you want to create (e.g.</info> my_db<info>):</info> ', $this->input->getOption('name'));
        $question->setValidator(function ($name) {
            if (! $name || ! vpsManager()->mysql()->isValidDBName($name)) {
                throw new Exception('Please enter a valid database/user name.');
            }

            return $name;
        });

        return $this->helper->ask($this->input, $this->output, $question);
    }
}
