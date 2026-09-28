<?php

namespace Gogol\VpsManagerCLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class SSLCreateCommand extends Command
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
        $this->setName('hosting:ssl')
            ->addArgument('domain', InputArgument::OPTIONAL, 'Domain name')
            ->addOption('domain', null, InputOption::VALUE_OPTIONAL, 'Domain name', null)
            ->setDescription('Set up Let\'s Encrypt SSL certificate for your domain/subdomain');
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
        $output->writeln('');

        return $this->setUpSSL($domain) ? Command::SUCCESS : Command::FAILURE;
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

        $question = new Question('<info>Please fill domain name of hosting you want to set up SSL certificates for (eg.</info> example.com<info>):</info> ', $this->input->getOption('domain'));
        $question->setValidator(function ($host) {
            if (! $host || ! isValidDomain($host)) {
                throw new \Exception('Please fill valid domain name.');
            }

            if (! vpsManager()->nginx()->exists($host)) {
                throw new \Exception('This hosting name does not exist.');
            }

            return $host;
        });

        return $this->helper->ask($this->input, $this->output, $question);
    }

    /**
     * Create SSL certificate for given domain.
     *
     * @param  string  $domain
     * @return bool
     */
    public function setUpSSL(string $domain): bool
    {
        $response = vpsManager()
            ->certbot()
            ->create($domain);

        if ($response->isError()) {
            $this->output->writeln('<error>'.$response->message.'</error>');

            return false;
        }

        $this->output->writeln('<info>'.$response->message.'</info>');

        return true;
    }
}
