<?php

namespace Gogol\VpsManagerCLI\Command\Fail2ban;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Fail2banShowCommand extends Command
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('fail2ban:show')
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

        if (! $fail2ban->isInstalled() || ! $fail2ban->isRunning()) {
            $output->writeln('<error>fail2ban is not running, check</error> systemctl status fail2ban');

            return Command::FAILURE;
        }

        $jails = $fail2ban->getJails();

        if ($jail = $input->getOption('jail')) {
            if (! in_array($jail, $jails)) {
                $output->writeln('<error>Jail '.$jail.' does not exist. Jails: '.implode(', ', $jails).'</error>');

                return Command::FAILURE;
            }

            $jails = [$jail];
        }

        $rows = [];

        foreach ($jails as $name) {
            $bans = $fail2ban->getBans($name);

            $output->writeln('<info>'.$name.'</info>: '.count($bans).' banned');

            foreach ($bans as $ban) {
                // Requests in the log of NGINX tell what the scanner tried
                $requests = str_starts_with($name, $fail2ban::SCANNERS_JAIL) ? $fail2ban->countScannerRequests($ban['ip']) : '';

                $rows[] = [$name, $ban['ip'], $ban['banned_at'], $ban['expires_at'], $requests];
            }
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
        $output->writeln('Unban an address: <comment>php vpsmanager fail2ban:remove-ip IP</comment>');

        return Command::SUCCESS;
    }
}
