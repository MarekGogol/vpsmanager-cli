<?php

namespace Gogol\VpsManagerCLI\Command\Laravel;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

// Shared domain and application selection of commands managing Laravel applications of a hosting.
abstract class LaravelCommand extends Command
{
    /**
     * The console input.
     *
     * @var \Symfony\Component\Console\Input\InputInterface
     */
    protected InputInterface $input;

    /**
     * The console output.
     *
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    protected OutputInterface $output;

    /**
     * The question helper.
     *
     * @var \Symfony\Component\Console\Helper\QuestionHelper
     */
    protected QuestionHelper $helper;

    /**
     * Add arguments and options shared by all Laravel commands.
     *
     * @return $this
     */
    protected function addSharedOptions(): static
    {
        return $this
            ->addArgument('domain', InputArgument::OPTIONAL, 'Domain name of the hosting')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Application path relative to the hosting data directory (web, sub/api...)')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Stop and remove the supervisor program');
    }

    /**
     * Boot the console of the command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return void
     */
    protected function bootCommand(InputInterface $input, OutputInterface $output): void
    {
        $this->input = $input;
        $this->output = $output;
        $this->helper = $this->getHelper('question');

        vpsManager()->bootConsole($output, $input, $this->helper);
    }

    /**
     * Get domain name of an existing hosting from argument or ask for it.
     *
     * @return string
     */
    protected function getDomainName(): string
    {
        $validator = function ($domain) {
            if (! $domain || ! isValidDomain($domain)) {
                throw new \Exception('Please fill valid domain name.');
            }

            if (! vpsManager()->nginx()->exists($domain)) {
                throw new \Exception('Hosting '.$domain.' does not exist.');
            }

            return vpsManager()->toUserFormat($domain);
        };

        if ($domain = $this->input->getArgument('domain')) {
            return $validator($domain);
        }

        $question = new Question('Please fill domain name of the hosting (eg. <info>example.com</info>): ');
        $question->setValidator($validator);

        return $this->helper->ask($this->input, $this->output, $question);
    }

    /**
     * Get the Laravel application from option or ask for it.
     *
     * @param  string  $domain
     * @return string
     *
     * @throws \Exception
     */
    protected function getApp(string $domain): string
    {
        $apps = vpsManager()->laravel()->getApps($domain);

        if (count($apps) === 0) {
            throw new \Exception('No Laravel application (directory with artisan file) has been found in '.vpsManager()->getWebPath($domain).'/web or /sub/*.');
        }

        if ($app = $this->input->getOption('path')) {
            $app = trim($app, '/');

            if (! array_key_exists($app, $apps)) {
                throw new \Exception('Laravel application '.$app.' has not been found. Available applications: '.implode(', ', array_keys($apps)).'.');
            }

            return $app;
        }

        $question = new ChoiceQuestion(
            'Select Laravel application of <info>'.$domain.'</info> ['.array_key_first($apps).']: ',
            array_keys($apps),
            0,
        );

        return $this->helper->ask($this->input, $this->output, $question);
    }

    /**
     * Get the PHP version of the hosting application.
     *
     * @param  string  $domain
     * @param  string  $app
     * @return string
     *
     * @throws \Exception
     */
    protected function getPHPVersion(string $domain, string $app): string
    {
        if (! ($version = vpsManager()->laravel()->getPHPVersion($domain, $app))) {
            throw new \Exception('PHP-FPM pool of hosting '.$domain.' has not been found.');
        }

        return $version;
    }

    /**
     * Stop when supervisor is not installed.
     *
     * @return void
     *
     * @throws \Exception
     */
    protected function ensureSupervisorIsInstalled(): void
    {
        if (! vpsManager()->supervisor()->isInstalled()) {
            throw new \Exception('Supervisor is not installed. Please install it with: apt install -y supervisor');
        }
    }

    /**
     * Write the response and get the command exit code.
     *
     * @param  \Gogol\VpsManagerCLI\Helpers\Response  $response
     * @return bool
     */
    protected function writeResponse($response): bool
    {
        $this->output->writeln($response->isError()
            ? '<error>'.strip_tags($response->message).'</error>'
            : '<info>'.$response->message.'</info>');

        return ! $response->isError();
    }
}
