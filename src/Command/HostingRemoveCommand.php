<?php

namespace Gogol\Gogol\VpsManagerCLI\Command;

use Gogol\Gogol\VpsManagerCLI\Nginx\Nginx;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class HostingRemoveCommand extends Command
{
    private $input;
    private $output;
    private $helper;

    protected function configure()
    {
        $this->setName('hosting:remove')
             ->setDescription('Delete all hosting configruations');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->helper = $this->getHelper('question');;

        vpsManager()->bootConsole($output, $input, $this->helper);

        $domain = $this->getDomainName();
        $output->writeln('');
        $delete_data = $this->askForStorageDelete($domain);
        $output->writeln('');
        $delete_mysql = $this->askForMysqlDelete($domain);

        $output->writeln('');
        $this->removeHosting($domain, $delete_data, $delete_mysql);
    }

    public function getDomainName()
    {
        $question = new Question('<info>Please fill domain name of hosting you want delete (eg.</info> example.com<info>):</info> ', null);
        $question->setValidator(function($host) {
            if ( ! $host || ! isValidDomain($host) )
                throw new \Exception('Please fill valid domain name.');

            if ( ! vpsManager()->nginx()->exists($host) )
                throw new \Exception('This hosting name does not exists.');

            return $host;
        });

        return $this->helper->ask($this->input, $this->output, $question);
    }

    public function askForStorageDelete($domain)
    {
        $question = new ConfirmationQuestion(
            '<info>Would you like to permanently delete all storage data?</info>'."\n".
            'Directory: <comment>'.vpsManager()->getWebpath($domain).'</comment>? (y/N) '
        , false);

        return $this->helper->ask($this->input, $this->output, $question);
    }

    public function askForMysqlDelete($domain)
    {
        $question = new ConfirmationQuestion(
            '<info>Would you like to permanently delete all users\'s mysql data?</info>'."\n".
            'User / database: <comment>'.vpsManager()->mysql()->dbName($domain).'</comment> (y/N) '
        , false);

        return $this->helper->ask($this->input, $this->output, $question);
    }

    public function isDev()
    {
        return $this->input->getOption('dev') > 1;
    }

    /*
     * Set host
     */
    private function removeHosting($domain, $delete_data = false, $delete_mysql = false)
    {
        $response = vpsManager()->hosting()->remove($domain, $delete_data, $delete_mysql);

        return $this->output->writeln('<info>'.$response->message.'</info>');;
    }
}