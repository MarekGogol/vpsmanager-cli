<?php

namespace Gogol\VpsManagerCLI\Command\Monitor;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MonitorRemoveIpCommand extends Command
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('monitor:remove-ip')
            ->setDescription('Unban the address in all fail2ban jails')
            ->addArgument('ip', InputArgument::REQUIRED, 'Banned IP address')
            ->addOption('jail', null, InputOption::VALUE_REQUIRED, 'Unban only in the given jail, e.g. sshd');
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
        $ip = trim((string) $input->getArgument('ip'));
        $jail = $input->getOption('jail');

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            $output->writeln('<error>'.$ip.' is not a valid IP address.</error>');

            return Command::FAILURE;
        }

        if (! $fail2ban->isInstalled() || ! $fail2ban->isRunning()) {
            $output->writeln('<error>fail2ban is not running, check</error> systemctl status fail2ban');

            return Command::FAILURE;
        }

        if ($jail && ! in_array($jail, $jails = $fail2ban->getJails())) {
            $output->writeln('<error>Jail '.$jail.' does not exist. Jails: '.implode(', ', $jails).'</error>');

            return Command::FAILURE;
        }

        $removed = $fail2ban->unban($ip, $jail);

        if ($removed === null) {
            $output->writeln('<error>fail2ban could not unban '.$ip.'.</error>');

            return Command::FAILURE;
        }

        if ($removed === 0) {
            $output->writeln('<comment>'.$ip.' is not banned'.($jail ? ' in '.$jail : '').'.</comment>');

            return Command::SUCCESS;
        }

        $output->writeln('<info>'.$ip.' has been unbanned'.($jail ? ' in '.$jail : '').'.</info>');
        $output->writeln('');
        $output->writeln('It is banned again when it keeps sending blocked requests. To never ban it, add it into');
        $output->writeln('<comment>/etc/fail2ban/jail.d/vpsmanager-scanners.local</comment> (see readme, Banning scanners with fail2ban).');

        return Command::SUCCESS;
    }
}
