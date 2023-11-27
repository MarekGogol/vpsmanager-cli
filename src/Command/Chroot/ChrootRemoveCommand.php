<?php

namespace Gogol\VpsManagerCLI\Command\Chroot;

use Gogol\VpsManagerCLI\Nginx\Nginx;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class ChrootRemoveCommand extends Command
{
    private $input;
    private $output;
    private $helper;

    protected function configure()
    {
        $this->setName('chroot:remove')
            ->addArgument('domain', InputArgument::OPTIONAL, 'Domain name')
            ->addOption('domain', null, InputOption::VALUE_OPTIONAL, 'Domain name', null)
            ->setDescription('Removes chroot directories for given domain');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->helper = $this->getHelper('question');

        vpsManager()->bootConsole($output, $input, $this->helper);

        $domain = $this->getDomainName();

        vpsManager()
            ->chroot()
            ->remove($domain)
            ->writeln(null, true);

        return Command::SUCCESS;
    }

    public function getDomainName()
    {
        if ($domain = $this->input->getArgument('domain')) {
            if (!isValidDomain($domain)) {
                $this->output->writeln('<error>Please fill valid domain name.</error>');
            } else {
                return $domain;
            }
        }

        $question = new Question('Please fill domain name for manage your chroot hosting (eg. <info>example.com</info>): ', $this->input->getOption('domain'));
        $question->setValidator(function ($host) {
            if (!$host || !isValidDomain($host)) {
                throw new \Exception('Please fill valid domain name.');
            }

            return $host;
        });

        return $this->helper->ask($this->input, $this->output, $question);
    }
}
