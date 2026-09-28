<?php

namespace Gogol\VpsManagerCLI\Command\Chroot;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

class ChrootCreateCommand extends Command
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
        $this->setName('chroot:create')
            ->addArgument('domain', InputArgument::OPTIONAL, 'Domain name')
            ->addOption('domain', null, InputOption::VALUE_OPTIONAL, 'Domain name', null)
            ->setDescription('Create or update chroot environment for given domain');
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
            ->create(
                $domain,
                [
                    'chroot' => true,
                    'php_version' => $this->getPHPVersion(),
                ],
                true,
            )
            ->writeln(false, true);

        return $response->isError() ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Ask for PHP CLI version used in chroot environment.
     *
     * @return string
     *
     * @throws \Exception
     */
    public function getPHPVersion(): string
    {
        $default = vpsManager()->config('php_version');

        $question = new ChoiceQuestion(
            'Set PHP CLI version of your domain. ['.$default.']: ',
            vpsManager()->php()->getVersions(),
            $default,
        );

        $version = $this->helper->ask($this->input, $this->output, $question) ?: $default;

        // Check if PHP version is installed
        if (! vpsManager()->php()->isInstalled($version)) {
            throw new \Exception('Required PHP version is not installed.');
        }

        return $version;
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
