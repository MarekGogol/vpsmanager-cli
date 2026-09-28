<?php

namespace Gogol\VpsManagerCLI\Command\Hosting;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class HostingRemoveCommand extends Command
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
        $this->setName('hosting:remove')
            ->addArgument('domain', InputArgument::OPTIONAL, 'Domain name')
            ->setDescription('Delete all hosting configurations');
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
        $delete_data = $this->askForStorageDelete($domain);
        $output->writeln('');
        $delete_mysql = $this->askForMysqlDelete($domain);
        $output->writeln('');

        $this->removeHosting($domain, $delete_data, $delete_mysql);

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

        $question = new Question('<info>Please fill domain name of hosting you want to delete (eg.</info> example.com<info>):</info> ', null);
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
     * Ask if storage data should be deleted.
     *
     * @param  string  $domain
     * @return bool
     */
    public function askForStorageDelete(string $domain): bool
    {
        $question = new ConfirmationQuestion(
            '<info>Would you like to permanently delete all storage data?</info>'."\n".
            'Directory: <comment>'.vpsManager()->getUserDirPath($domain).'</comment>? (y/N) [n]: ',
            false,
        );

        return (bool) $this->helper->ask($this->input, $this->output, $question);
    }

    /**
     * Ask if mysql user and database should be deleted.
     *
     * @param  string  $domain
     * @return bool
     */
    public function askForMysqlDelete(string $domain): bool
    {
        $question = new ConfirmationQuestion(
            '<info>Would you like to permanently delete all user\'s MySQL data?</info>'."\n".
            'User / database: <comment>'.vpsManager()->mysql()->dbName($domain).'</comment> (y/N) [n]: ',
            false,
        );

        return (bool) $this->helper->ask($this->input, $this->output, $question);
    }

    /**
     * Remove the hosting.
     *
     * @param  string  $domain
     * @param  bool  $delete_data
     * @param  bool  $delete_mysql
     * @return void
     */
    private function removeHosting(string $domain, bool $delete_data = false, bool $delete_mysql = false): void
    {
        $response = vpsManager()
            ->hosting()
            ->remove($domain, $delete_data, $delete_mysql);

        $this->output->writeln('<info>'.$response->message.'</info>');
    }
}
