<?php

namespace Gogol\VpsManagerCLI\Command\Monitor;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MonitorListCommand extends Command
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('monitor:list')
            ->setDescription('List addresses banned by fail2ban in all jails')
            ->addOption('jail', null, InputOption::VALUE_REQUIRED, 'Only the given jail, e.g. vpsmanager-scanners');
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
        vpsManager()->bootConsole($output, $input, $this->getHelper('question'));

        $fail2ban = vpsManager()->fail2ban();
        $rows = [];
        $jail = $input->getOption('jail');
        $found = false;

        // Behind a router the router bans the scanners, this server only its own SSH
        foreach ($this->getFail2bans() as $where => $server) {
            if (! $server->isInstalled() || ! $server->isRunning()) {
                $output->writeln('<error>fail2ban of '.$where.' is not running.</error>');

                continue;
            }

            $prefix = $server->isRouter() ? 'router: ' : '';

            foreach ($server->getJails() as $name) {
                if ($jail && $jail !== $name) {
                    continue;
                }

                $found = true;
                $bans = $server->getBans($name);

                $output->writeln('<info>'.$prefix.$name.'</info>: '.count($bans).' banned');

                foreach ($bans as $ban) {
                    // Requests in the log of NGINX of this server tell what the scanner tried
                    $requests = str_starts_with($name, $fail2ban::SCANNERS_JAIL) ? $fail2ban->countScannerRequests($ban['ip']) : '';

                    $rows[] = [$prefix.$name, $ban['ip'], $ban['banned_at'], $ban['expires_at'], $requests];
                }
            }
        }

        if ($jail && ! $found) {
            $output->writeln('<error>Jail '.$jail.' does not exist.</error>');

            return Command::FAILURE;
        }

        if (count($rows) === 0) {
            return Command::SUCCESS;
        }

        $output->writeln('');

        (new Table($output))
            ->setHeaders(['Jail', 'IP', 'Banned at', 'Expires at', 'Blocked requests'])
            ->setRows($rows)
            ->render();

        $output->writeln('');
        $output->writeln('Requests of an address: <comment>grep "^IP " '.$fail2ban::SCANNERS_LOG.'</comment>');
        $output->writeln('Unban an address: <comment>php vpsmanager monitor:remove-ip IP</comment>');

        return Command::SUCCESS;
    }

    /**
     * fail2ban of this server, and of the router in front of it.
     *
     * @return array label => \Gogol\VpsManagerCLI\Helpers\Fail2ban
     */
    protected function getFail2bans(): array
    {
        $servers = ['this server' => vpsManager()->fail2ban()];

        if (($router = vpsManager()->router())->isEnabled() && $router->getDestination()) {
            $servers['the router '.$router->getDestination()] = $router->fail2ban();
        }

        return $servers;
    }
}
