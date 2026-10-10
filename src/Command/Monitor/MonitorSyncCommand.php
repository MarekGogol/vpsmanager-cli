<?php

namespace Gogol\VpsManagerCLI\Command\Monitor;

use Gogol\VpsManagerCLI\Helpers\Fail2ban;
use Gogol\VpsManagerCLI\Helpers\SharedBans;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MonitorSyncCommand extends Command
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('monitor:sync')
            ->setDescription('Share banned addresses with the other servers through the monitor: report the bans of repeated scanners with their level, ban the addresses of the others')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only print what would be reported, banned and unbanned');
    }

    /**
     * Execute the command. The monitor is optional: when it does not answer, nothing changes and the bans
     * of this server work as before. Only changed addresses are banned or unbanned, fail2ban is never reloaded.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        vpsManager()->bootConsole($output, $input, $this->getHelper('question'));

        $dryRun = (bool) $input->getOption('dry-run');
        $monitor = vpsManager()->monitor();

        if (! ($url = $monitor->getSyncUrl())) {
            $output->writeln('<comment>The monitor is not known: no agent of the monitor in '.$monitor::AGENT_SCRIPT.' and no monitor_sync_url in the configuration.</comment>');

            return Command::SUCCESS;
        }

        $servers = $this->getFail2bans($output);

        if (count($servers) === 0) {
            return Command::SUCCESS;
        }

        // Bans of the scanners banned again and again (1, 7 or 30 days), the most certain ones
        $reported = [];

        foreach ($servers as $fail2ban) {
            foreach (SharedBans::report($fail2ban->getBans(Fail2ban::RECIDIVE_JAIL), $fail2ban->getTimezoneOffset(), Fail2ban::RECIDIVE_JAIL) as $row) {
                $reported[$row['ip']] = $row;
            }
        }

        if ($dryRun) {
            $output->writeln('Would report '.count($reported).' addresses to the monitor.');
        } elseif (count($reported) && $monitor->requestMonitor('POST', $url, ['ips' => array_values($reported)], $error) === null) {
            $output->writeln('<comment>The monitor did not accept the reported addresses ('.$error.'), they are reported with the next sync.</comment>');
        } else {
            $output->writeln('Reported '.count($reported).' addresses to the monitor.');
        }

        if (($response = $monitor->requestMonitor('GET', $url, null, $error)) === null || ! isset($response['data'])) {
            $output->writeln('<comment>The monitor did not answer ('.($error ?? 'no data').'), shared bans are kept as they are.</comment>');

            return Command::SUCCESS;
        }

        // ip => days, the monitor without levels shares the longest ban
        $shared = [];

        foreach ($response['data'] as $row) {
            if (filter_var($row['ip'] ?? null, FILTER_VALIDATE_IP)) {
                $shared[$row['ip']] = (int) ($row['days'] ?? max(SharedBans::LEVELS));
            }
        }

        $output->writeln('The monitor shares '.count($shared).' addresses.');

        foreach ($servers as $label => $fail2ban) {
            $this->syncServer($output, $label, $fail2ban, $shared, $dryRun);
        }

        return Command::SUCCESS;
    }

    /**
     * Ban the shared addresses in the jail of their level and unban those the monitor does not share any more.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  string  $label
     * @param  \Gogol\VpsManagerCLI\Helpers\Fail2ban  $fail2ban
     * @param  array  $shared  ip => days
     * @param  bool  $dryRun
     * @return void
     */
    protected function syncServer(OutputInterface $output, string $label, Fail2ban $fail2ban, array $shared, bool $dryRun): void
    {
        $ignored = $fail2ban->getIgnoredIps();

        // Own addresses and private networks are never banned, addresses of the recidive jail are banned already
        $skip = array_column($fail2ban->getBans(Fail2ban::RECIDIVE_JAIL), 'ip');

        foreach (array_keys($shared) as $ip) {
            if ($fail2ban->isIgnoredIp((string) $ip, $ignored)) {
                $skip[] = (string) $ip;
            }
        }

        $current = [];

        foreach (SharedBans::JAILS as $jail) {
            $current[$jail] = array_column($fail2ban->getBans($jail), 'ip');
        }

        $plan = SharedBans::plan($shared, $current, $skip);

        if (count($plan) === 0) {
            $output->writeln(ucfirst($label).': shared bans are up to date ('.count(array_merge(...array_values($current))).').');

            return;
        }

        // Bans first, an address moving to the jail of another level is never unbanned meanwhile
        foreach ($plan as $jail => $changes) {
            if (! $dryRun && ! $fail2ban->setBans($jail, $changes['ban'])) {
                $output->writeln('<error>'.ucfirst($label).': fail2ban could not ban the addresses of '.$jail.'.</error>');

                return;
            }
        }

        foreach ($plan as $jail => $changes) {
            if (! $dryRun && ! $fail2ban->setBans($jail, $changes['unban'], false)) {
                $output->writeln('<error>'.ucfirst($label).': fail2ban could not unban the addresses of '.$jail.'.</error>');

                return;
            }

            $output->writeln(ucfirst($label).' '.$jail.': '.($dryRun ? 'would ban ' : 'banned ').count($changes['ban']).', '.($dryRun ? 'would unban ' : 'unbanned ').count($changes['unban']).'.');
        }
    }

    /**
     * fail2ban which bans the scanners: of this server, or of every router in front of it. Servers without
     * the shared jails are skipped until monitor:install adds them.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return array label => \Gogol\VpsManagerCLI\Helpers\Fail2ban
     */
    protected function getFail2bans(OutputInterface $output): array
    {
        $router = vpsManager()->router();
        $servers = [];

        if ($router->isEnabled()) {
            foreach ($router->getRouters() as $item) {
                $servers[$item->getLabel()] = $item->fail2ban();
            }
        } else {
            $servers['this server'] = vpsManager()->fail2ban();
        }

        foreach ($servers as $label => $fail2ban) {
            $missing = $fail2ban->isInstalled() && $fail2ban->isRunning()
                ? array_diff(SharedBans::JAILS, $fail2ban->getJails())
                : SharedBans::JAILS;

            if (count($missing)) {
                $output->writeln('<comment>'.ucfirst($label).' has no running jails '.implode(', ', $missing).', run php vpsmanager monitor:install.</comment>');

                unset($servers[$label]);
            }
        }

        return $servers;
    }
}
