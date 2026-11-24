<?php

namespace Gogol\VpsManagerCLI\Command\Cleanup;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CleanAllCommand extends Command
{
    /**
     * Cleanup commands run by clean:all, in their order.
     */
    const COMMANDS = ['clean:sessions', 'clean:cache'];

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('clean:all')
            ->setDescription('Run all cleanup commands: '.implode(', ', self::COMMANDS))
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only print what would be removed');
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
        $result = Command::SUCCESS;

        foreach (self::COMMANDS as $i => $name) {
            if ($i > 0) {
                $output->writeln('');
            }

            $output->writeln('<info>'.$name.'</info>');

            $arguments = $input->getOption('dry-run') ? ['--dry-run' => true] : [];

            // A failed command does not stop the others
            if ($this->getApplication()->find($name)->run(new ArrayInput($arguments), $output) !== Command::SUCCESS) {
                $result = Command::FAILURE;
            }
        }

        return $result;
    }
}
