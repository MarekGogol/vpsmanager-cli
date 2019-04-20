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

class BackupPerformCommand extends Command
{
    private $input;
    private $output;
    private $helper;

    protected function configure()
    {
        $this->setName('backup:create')
             ->setDescription('Backup all databases, websites data, and other files. Also copies data to other server.')
             ->addOption('databases', null, InputOption::VALUE_OPTIONAL, 'Backup all databases', false)
             ->addOption('dirs', null, InputOption::VALUE_OPTIONAL, 'Backup all directories', false)
             ->addOption('www', null, InputOption::VALUE_OPTIONAL, 'Backup all www data', false);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->helper = $this->getHelper('question');;

        vpsManager()->bootConsole($output, $input, $this->helper);

        if ( ! vpsManager()->config('backup_path') )
            throw new \Exception('Please, first start backups configuration with "php vpsmanager backup:setup" command.');

        if ( count($missing = vpsManager()->backup()->checkRequirements()) > 0 )
            throw new \Exception('Please, first install missing extensions "apt install -y '.implode(' ', $missing).'"');

        //If any parameter has been filled, then everything will be backuped
        $any = $input->getOption('databases') === false
            && $input->getOption('dirs') === false
            && $input->getOption('www') === false;

        if ( ($response = vpsManager()->backup()->perform([
            'databases' => $any || $input->getOption('databases') === null,
            'dirs' => $any || $input->getOption('dirs') === null,
            'www' => $any || $input->getOption('www') === null,
        ]))->isError() )
            throw new \Exception($response->message);

        $this->output->writeln('<info>'.$response->message.'</info>');
    }
}