<?php

namespace Gogol\VpsManagerCLI\Command\Chroot;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class ChrootRemoveCommand extends Command
{
    /**
     * The console input.
     *
     * @var \Symfony\Component\Console\Input\InputInterface
     */
    private InputInterface $input;

    /**
     * The console output.
     *
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    private OutputInterface $output;

    /**
     * The question helper.
     *
     * @var \Symfony\Component\Console\Helper\QuestionHelper
     */
    private QuestionHelper $helper;

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('chroot:remove')
            ->addArgument('domain', InputArgument::OPTIONAL, 'Domain name')
            ->addOption('domain', null, InputOption::VALUE_OPTIONAL, 'Domain name', null)
            ->setDescription('Remove chroot directories for given domain');
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
        $this->input = $input;
        $this->output = $output;
        $this->helper = $this->getHelper('question');

        vpsManager()->bootConsole($output, $input, $this->helper);

        $domain = $this->getDomainName();

        $response = vpsManager()
            ->chroot()
            ->remove($domain)
            ->writeln(null, true);

        return $response->isError() ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Get domain name from argument or ask for it.
     *
     * @return string
     */
    public function getDomainName(): string
    {
        if ($domain = $this->input->getArgument('domain')) {
            if (isValidDomain($domain)) {
                return $domain;
            }

            $this->output->writeln('<error>Please fill valid domain name.</error>');
        }

        $question = new Question('Please fill domain name to manage your chroot hosting (eg. <info>example.com</info>): ', $this->input->getOption('domain'));
        $question->setValidator(function ($host) {
            if (! $host || ! isValidDomain($host)) {
                throw new \Exception('Please fill valid domain name.');
            }

            return $host;
        });

        return $this->helper->ask($this->input, $this->output, $question);
    }
}
