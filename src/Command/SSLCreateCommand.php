<?php

namespace Gogol\VpsManagerCLI\Command;

use Gogol\VpsManagerCLI\Nginx\Nginx;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class SSLCreateCommand extends Command
{
    private $input;
    private $output;
    private $helper;

    protected function configure()
    {
        $this->setName('hosting:ssl')
             ->addOption('domain', null, InputOption::VALUE_OPTIONAL, 'Domain name', null)
             ->setDescription('Set up lets encrypt SSL certificate for your domain/subdomain');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->helper = $this->getHelper('question');

        vpsManager()->bootConsole($output, $input, $this->helper);

        $domain = $this->getDomainName();
        $output->writeln('');
        $delete_data = $this->setUpSSL($domain);
    }

    public function getDomainName()
    {
        $question = new Question('<info>Please fill domain name of hosting you want set up SSL certificates. (eg.</info> example.com<info>):</info> ', $this->input->getOption('domain'));
        $question->setValidator(function($host) {
            if ( ! $host || ! isValidDomain($host) )
                throw new \Exception('Please fill valid domain name.');

            if ( ! vpsManager()->nginx()->exists($host) )
                throw new \Exception('This hosting name does not exists.');

            return $host;
        });

        return $this->helper->ask($this->input, $this->output, $question);
    }

    public function setUpSSL($domain)
    {
        $response = vpsManager()->certbot()->create($domain);

        $this->output->writeln('<info>'.$response->message.'</info>');
    }

    public function isDev()
    {
        return $this->input->getOption('dev') > 1;
    }
}