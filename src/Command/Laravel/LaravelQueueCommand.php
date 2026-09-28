<?php

namespace Gogol\VpsManagerCLI\Command\Laravel;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LaravelQueueCommand extends LaravelCommand
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('laravel:queue')
            ->setDescription('Run Laravel queue workers of the hosting application in supervisor')
            ->addSharedOptions()
            ->addOption('workers', null, InputOption::VALUE_REQUIRED, 'Number of worker processes', 2)
            ->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Queues to work, eg. high,default');
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
        $this->bootCommand($input, $output);
        $this->ensureSupervisorIsInstalled();

        $domain = $this->getDomainName();
        $app = $this->getApp($domain);
        $program = vpsManager()->laravel()->getProgramName($domain, $app, 'queue');

        if ($input->getOption('remove')) {
            return $this->writeResponse(vpsManager()->supervisor()->remove($domain, $program)) ? Command::SUCCESS : Command::FAILURE;
        }

        $workers = (int) $input->getOption('workers');

        if ($workers < 1) {
            throw new \Exception('Number of workers must be at least 1.');
        }

        $queue = $input->getOption('queue');

        $section = (string) vpsManager()->getStub('supervisor.queue.conf')
            ->replace('{program}', $program)
            ->replace('{app}', $app)
            ->replace('{user}', $domain)
            ->replace('{php_bin}', vpsManager()->php()->getPhpBinPath($this->getPHPVersion($domain)))
            ->replace('{path}', vpsManager()->laravel()->getApps($domain)[$app])
            ->replace('{queue}', $queue ? ' --queue='.escapeshellarg($queue) : '')
            ->replace('{workers}', $workers);

        if (! $this->writeResponse(vpsManager()->supervisor()->save($domain, $program, $section))) {
            return Command::FAILURE;
        }

        $output->writeln(vpsManager()->supervisor()->status($program));
        $output->writeln('');
        $output->writeln('After each deploy restart workers with <comment>php artisan queue:restart</comment>.');

        return Command::SUCCESS;
    }
}
