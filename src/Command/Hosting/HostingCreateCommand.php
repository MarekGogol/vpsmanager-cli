<?php

namespace Gogol\VpsManagerCLI\Command\Hosting;

use Gogol\VpsManagerCLI\Nginx\Nginx;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class HostingCreateCommand extends Command
{
    private $input;
    private $output;
    private $helper;

    protected function configure()
    {
        $this->setName('hosting:create')
            ->addArgument('domain', InputArgument::OPTIONAL, 'Domain name')
            ->setDescription('Create new hosting with full php/mysql/nginx setup')
            ->addOption('domain', null, InputOption::VALUE_OPTIONAL, 'Domain name', null)
            ->addOption('php_version', null, InputOption::VALUE_OPTIONAL, 'PHP Version', null)
            ->addOption('dev', null, InputOption::VALUE_OPTIONAL, 'Use dev version of command', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->helper = $this->getHelper('question');

        vpsManager()->bootConsole($output, $input, $this->helper);

        $domain = $this->getDomainName();
        $php_version = $this->getPHPVersion();
        $chroot = $this->getChrootEnabled();
        $database = $this->askForCreatingDatabase();

        //Remove all hosting settings
        //If dev parameter will be presented as number 2, all www data and database will be destroyed!
        if ($this->isDev()) {
            vpsManager()
                ->hosting()
                ->remove($domain, $this->input->getOption('dev') == 2);
        }

        $this->generateManagerHosting($domain, $php_version, $database, $chroot);

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

        $question = new Question('Please fill domain name of your new hosting (eg. <info>example.com</info>): ', $this->input->getOption('domain'));
        $question->setValidator(function ($host) {
            if (!$host || !isValidDomain($host)) {
                throw new \Exception('Please fill valid domain name.');
            }

            return $host;
        });

        return $this->helper->ask($this->input, $this->output, $question);
    }

    public function getPHPVersion()
    {
        $default = vpsManager()->config('php_version');

        //Nginx path
        $question = new ChoiceQuestion(
            'Set PHP Version of your domain. [' . $default . ']: ',
            vpsManager()
                ->php()
                ->getVersions(),
            $default,
        );

        $version = $this->helper->ask($this->input, $this->output, $question) ?: $default;

        //Check if is PHP Version installed
        if (($php = vpsManager()->php())->isInstalled($version)) {
            return $version;
        } else {
            throw new \Exception('Required PHP Version is not installed.');
        }
    }

    public function getChrootEnabled()
    {
        $question = new ConfirmationQuestion('Would you like to set chroot environment for this user? (y/N) [N]: ', false);

        return $this->helper->ask($this->input, $this->output, $question);
    }

    public function askForCreatingDatabase()
    {
        $question = new ConfirmationQuestion('Would you like to create MySql <info>user</info> and <info>database</info> for this domain? (y/N) [N]: ', false);

        return $this->helper->ask($this->input, $this->output, $question);
    }

    public function isDev()
    {
        return $this->input->getOption('dev') > 1;
    }

    /*
     * Set host
     */
    private function generateManagerHosting($domain, $php_version, $database = false, $chroot = false)
    {
        if (
            ($response = vpsManager()
                ->hosting()
                ->create($domain, [
                    'php_version' => $php_version,
                    'database' => $database,
                    'chroot' => $chroot,
                ]))->isError()
        ) {
            throw new \Exception($response->message);
        }

        $this->output->writeln('<info>' . $response->message . '</info>');
    }
}
