<?php

namespace Gogol\VpsManagerCLI\Command\Hosting;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class HostingCreateCommand extends Command
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
        $this->setName('hosting:create')
            ->addArgument('domain', InputArgument::OPTIONAL, 'Domain name')
            ->setDescription('Create new hosting with full php/mysql/nginx setup')
            ->addOption('domain', null, InputOption::VALUE_OPTIONAL, 'Domain name', null)
            ->addOption('php_version', null, InputOption::VALUE_OPTIONAL, 'PHP Version', null);
    }

    /**
     * Execute the command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return int
     *
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;
        $this->helper = $this->getHelper('question');

        vpsManager()->bootConsole($output, $input, $this->helper);

        $domain = $this->getDomainName();
        $php_version = $this->getPHPVersion();
        $chroot = $this->getChrootEnabled();
        $database = $this->askForCreatingDatabase();

        $this->generateManagerHosting($domain, $php_version, $database, $chroot);

        return Command::SUCCESS;
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

        $question = new Question('Please fill domain name of your new hosting (eg. <info>example.com</info>): ', $this->input->getOption('domain'));
        $question->setValidator(function ($host) {
            if (! $host || ! isValidDomain($host)) {
                throw new \Exception('Please fill valid domain name.');
            }

            return $host;
        });

        return $this->helper->ask($this->input, $this->output, $question);
    }

    /**
     * Get PHP version from option or ask for it.
     *
     * @return string
     *
     * @throws \Exception
     */
    public function getPHPVersion(): string
    {
        $default = vpsManager()->config('php_version');

        if (! ($version = $this->input->getOption('php_version'))) {
            $question = new ChoiceQuestion(
                'Set PHP version of your domain. ['.$default.']: ',
                vpsManager()->php()->getVersions(),
                $default,
            );

            $version = $this->helper->ask($this->input, $this->output, $question) ?: $default;
        }

        // Check if PHP version is installed
        if (! vpsManager()->php()->isInstalled($version)) {
            throw new \Exception('Required PHP version is not installed.');
        }

        return $version;
    }

    /**
     * Ask if chroot environment should be created.
     *
     * @return bool
     */
    public function getChrootEnabled(): bool
    {
        $question = new ConfirmationQuestion('Would you like to set chroot environment for this user? (y/N) [N]: ', false);

        return (bool) $this->helper->ask($this->input, $this->output, $question);
    }

    /**
     * Ask if mysql user and database should be created.
     *
     * @return bool
     */
    public function askForCreatingDatabase(): bool
    {
        $question = new ConfirmationQuestion('Would you like to create MySQL <info>user</info> and <info>database</info> for this domain? (y/N) [N]: ', false);

        return (bool) $this->helper->ask($this->input, $this->output, $question);
    }

    /**
     * Create the hosting.
     *
     * @param  string  $domain
     * @param  string  $php_version
     * @param  bool  $database
     * @param  bool  $chroot
     * @return void
     *
     * @throws \Exception
     */
    private function generateManagerHosting(string $domain, string $php_version, bool $database = false, bool $chroot = false): void
    {
        $response = vpsManager()
            ->hosting()
            ->create($domain, [
                'php_version' => $php_version,
                'database' => $database,
                'chroot' => $chroot,
            ]);

        if ($response->isError()) {
            throw new \Exception($response->message);
        }

        $this->output->writeln('<info>'.$response->message.'</info>');
    }
}
