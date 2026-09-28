<?php

namespace Gogol\VpsManagerCLI\Command\Laravel;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class LaravelOctaneCommand extends LaravelCommand
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('laravel:octane')
            ->setDescription('Run Laravel Octane (RoadRunner) server of the hosting application in supervisor')
            ->addSharedOptions()
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'OCTANE_PORT written into .env when it is not set yet (first free port from 8100 by default)')
            ->addOption('nginx', null, InputOption::VALUE_NEGATABLE, 'Proxy the application from NGINX to the Octane server');
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
        $laravel = vpsManager()->laravel();
        $program = $laravel->getProgramName($domain, $app, 'octane');

        if ($input->getOption('remove')) {
            return $this->remove($domain, $program);
        }

        $path = $laravel->getApps($domain)[$app];

        if ($missing = $laravel->getMissingOctaneRequirements($path)) {
            $output->writeln('<error>Laravel Octane with RoadRunner is not installed in '.$path.'.</error> Missing:');

            foreach ($missing as $requirement) {
                $output->writeln(' - '.$requirement);
            }

            $output->writeln('Install it with <comment>composer require laravel/octane spiral/roadrunner-cli spiral/roadrunner-http</comment> and <comment>php artisan octane:install --server=roadrunner</comment>.');

            return Command::FAILURE;
        }

        if (! ($port = $this->getOctanePort($path))) {
            return Command::FAILURE;
        }

        $this->warnAboutMissingConfig($path);

        $section = (string) vpsManager()->getStub('supervisor.octane.conf')
            ->replace('{program}', $program)
            ->replace('{app}', $app)
            ->replace('{user}', $domain)
            ->replace('{php_bin}', vpsManager()->php()->getPhpBinPath($this->getPHPVersion($domain)))
            ->replace('{path}', $path);

        if (! $this->writeResponse(vpsManager()->supervisor()->save($domain, $program, $section))) {
            return Command::FAILURE;
        }

        $output->writeln(vpsManager()->supervisor()->status($program));

        if ($this->shouldUpdateNginx($laravel->getAppHost($domain, $app))
            && ! $this->writeResponse($laravel->enableOctaneNginx($domain, $app, $program, $port))) {
            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln('Octane server listens on <comment>127.0.0.1:'.$port.'</comment>. After each deploy reload it with <comment>php artisan octane:reload</comment>.');
        $output->writeln('When you change <comment>OCTANE_*</comment> variables in .env, run this command again to apply them.');

        return Command::SUCCESS;
    }

    /**
     * Get OCTANE_PORT of the application, or write the Octane settings into its .env file.
     *
     * @param  string  $path
     * @return int|null
     *
     * @throws \Exception
     */
    protected function getOctanePort(string $path): ?int
    {
        $laravel = vpsManager()->laravel();

        if (! file_exists($path.'/.env')) {
            throw new \Exception('File '.$path.'/.env does not exist.');
        }

        if (($server = $laravel->getEnv($path, 'OCTANE_SERVER')) && $server !== 'roadrunner') {
            throw new \Exception('Only RoadRunner Octane server is supported, but OCTANE_SERVER='.$server.' is set in '.$path.'/.env.');
        }

        if (($host = $laravel->getEnv($path, 'OCTANE_HOST')) && $host !== '127.0.0.1') {
            throw new \Exception('Octane server must listen on 127.0.0.1 behind NGINX, but OCTANE_HOST='.$host.' is set in '.$path.'/.env.');
        }

        // Octane settings are already in .env, but the port must not collide with other applications
        if ($port = $laravel->getOctanePort($path)) {
            foreach ($laravel->getUsedOctanePorts() as $otherPath => $otherPort) {
                if ($otherPort === $port && $otherPath !== $path) {
                    throw new \Exception('OCTANE_PORT='.$port.' is already used by '.$otherPath.'.');
                }
            }

            if (! $server) {
                $laravel->setEnv($path, ['OCTANE_SERVER' => 'roadrunner']);
            }

            return $port;
        }

        $port = (int) ($this->input->getOption('port') ?: $laravel->getFreeOctanePort());
        $values = ['OCTANE_SERVER' => 'roadrunner', 'OCTANE_HOST' => '127.0.0.1', 'OCTANE_PORT' => $port];

        $this->output->writeln('<comment>OCTANE_PORT is not set in '.$path.'/.env.</comment>');

        $question = new ConfirmationQuestion('Do you want to add <info>'.http_build_query($values, '', ', ').'</info> into .env? (Y/n) [Y]: ', true);

        if (! $this->helper->ask($this->input, $this->output, $question)) {
            $this->output->writeln('<error>Please set OCTANE_SERVER, OCTANE_HOST and OCTANE_PORT in .env of the application.</error>');

            return null;
        }

        $laravel->setEnv($path, $values);

        return $port;
    }

    /**
     * Warn when config/octane.php does not read workers settings from .env.
     *
     * @param  string  $path
     * @return void
     */
    protected function warnAboutMissingConfig(string $path): void
    {
        $config = file_exists($path.'/config/octane.php') ? file_get_contents($path.'/config/octane.php') : '';

        foreach (['workers' => 'OCTANE_WORKERS', 'max_requests' => 'OCTANE_MAX_REQUESTS'] as $key => $env) {
            if (! str_contains($config, $env)) {
                $this->output->writeln('<comment>'.$env.' is not used in config/octane.php, add</comment> \''.$key.'\' => env(\''.$env.'\', ...)<comment> to set it from .env.</comment>');
            }
        }
    }

    /**
     * Stop the Octane server and serve the application with PHP-FPM again.
     *
     * @param  string  $domain
     * @param  string  $program
     * @return int
     */
    protected function remove(string $domain, string $program): int
    {
        $nginx = $this->writeResponse(vpsManager()->laravel()->disableOctaneNginx($domain, $program));
        $supervisor = $this->writeResponse(vpsManager()->supervisor()->remove($domain, $program));

        return $nginx && $supervisor ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Determine if NGINX should proxy the application to the Octane server.
     *
     * @param  string  $host
     * @return bool
     */
    protected function shouldUpdateNginx(string $host): bool
    {
        if (($nginx = $this->input->getOption('nginx')) !== null) {
            return $nginx;
        }

        $question = new ConfirmationQuestion('Do you want to proxy <info>'.$host.'</info> from NGINX to the Octane server? (Y/n) [Y]: ', true);

        return (bool) $this->helper->ask($this->input, $this->output, $question);
    }
}
