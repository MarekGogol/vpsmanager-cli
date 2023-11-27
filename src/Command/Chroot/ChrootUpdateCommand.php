<?php

namespace Gogol\VpsManagerCLI\Command\Chroot;

use Gogol\VpsManagerCLI\Nginx\Nginx;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class ChrootUpdateCommand extends Command
{
    private $input;
    private $output;
    private $helper;

    protected function configure()
    {
        $this->setName('chroot:update')->setDescription('Update all available chroot instances');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->helper = $this->getHelper('question');

        vpsManager()->bootConsole($output, $input, $this->helper);

        vpsManager()
            ->chroot()
            ->update()
            ->writeln(null, true);

        return Command::SUCCESS;
    }
}
