<?php

namespace Gogol\VpsManagerCLI\Command\Monitor;

use Gogol\VpsManagerCLI\Helpers\SharedBans;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MonitorIpDenyCommand extends Command
{
    /**
     * Reason of the ban in the monitor.
     */
    const REASON = 'manual';

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('monitor:ip:deny')
            ->setDescription('Ban the address now on this server and share the ban through the monitor with all servers')
            ->addArgument('ip', InputArgument::REQUIRED, 'IP address')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Length of the ban: '.implode(', ', SharedBans::LEVELS).' days', max(SharedBans::LEVELS))
            ->addOption('local', null, InputOption::VALUE_NONE, 'Only ban it on this server (and its routers), the monitor does not share it');
    }

    /**
     * Execute the command. The address is banned in the shared jail of its level, so its ban ends by itself.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        vpsManager()->bootConsole($output, $input, $this->getHelper('question'));
        vpsManager()->monitor()->useServerTimezone();

        $ip = trim((string) $input->getArgument('ip'));
        $days = (int) $input->getOption('days');
        $agent = vpsManager()->monitorAgent();

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            $output->writeln('<error>'.$ip.' is not a valid IP address.</error>');

            return Command::FAILURE;
        }

        if (! in_array($days, SharedBans::LEVELS, true)) {
            $output->writeln('<error>The ban lasts '.implode(', ', SharedBans::LEVELS).' days.</error>');

            return Command::FAILURE;
        }

        $servers = $agent->getFail2bans($output);

        if (count($servers) === 0) {
            return Command::FAILURE;
        }

        $jail = SharedBans::jailOf($days);

        foreach ($servers as $label => $fail2ban) {
            // Own addresses, private networks and crawlers of search engines are never banned
            if ($fail2ban->isIgnoredIp($ip)) {
                $output->writeln('<error>'.$ip.' is ignored by fail2ban of '.$label.' (own address, private network or a crawler), it is never banned.</error>');

                return Command::FAILURE;
            }

            if (! $fail2ban->setBans($jail, [$ip])) {
                $output->writeln('<error>fail2ban of '.$label.' could not ban '.$ip.'.</error>');

                return Command::FAILURE;
            }

            $output->writeln('<info>'.$ip.' is banned on '.$label.' for '.$days.' '.($days === 1 ? 'day' : 'days').' ('.$jail.').</info>');
        }

        if ($input->getOption('local')) {
            return Command::SUCCESS;
        }

        // monitor:collect keeps the ban also before the monitor answers its change
        $state = $agent->loadState();
        $state['shared'][$ip] = $days;
        $agent->saveState($state);

        $row = [
            'ip' => $ip,
            'reason' => self::REASON,
            'days' => $days,
            'expires_at' => date('c', time() + $days * 86400),
            'publish' => true,
        ];

        if ($agent->request('POST', 'blocked-ips', ['ips' => [$row]], $error) === null) {
            $output->writeln('<error>The monitor did not accept '.$ip.' ('.($error ?? 'no answer').'), the other servers do not ban it.</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>'.$ip.' is shared by the monitor, all servers ban it with their next monitor:collect (within 5 minutes).</info>');

        return Command::SUCCESS;
    }
}
