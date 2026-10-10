<?php

namespace Gogol\VpsManagerCLI\Command\Monitor;

use Gogol\VpsManagerCLI\Helpers\Fail2ban;
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
            ->setDescription('Share banned addresses with the other servers through the monitor: report the week bans, ban the addresses of the others')
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

        // Week bans of the scanners banned again and again, the most certain ones
        $reported = [];

        foreach ($servers as $fail2ban) {
            foreach ($fail2ban->getBans(Fail2ban::RECIDIVE_JAIL) as $ban) {
                $reported[$ban['ip']] = ['ip' => $ban['ip'], 'reason' => Fail2ban::RECIDIVE_JAIL];
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

        $shared = array_values(array_unique(array_filter(array_column($response['data'], 'ip'), fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP))));

        $output->writeln('The monitor shares '.count($shared).' addresses.');

        foreach ($servers as $label => $fail2ban) {
            $this->syncServer($output, $label, $fail2ban, $shared, $dryRun);
        }

        return Command::SUCCESS;
    }

    /**
     * Ban the new shared addresses and unban those the monitor does not share any more, in the shared jail of one server.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  string  $label
     * @param  \Gogol\VpsManagerCLI\Helpers\Fail2ban  $fail2ban
     * @param  array  $shared
     * @param  bool  $dryRun
     * @return void
     */
    protected function syncServer(OutputInterface $output, string $label, Fail2ban $fail2ban, array $shared, bool $dryRun): void
    {
        $ignored = $fail2ban->getIgnoredIps();

        // Own addresses and private networks are never banned, addresses of the week jail are banned already
        $recidive = array_column($fail2ban->getBans(Fail2ban::RECIDIVE_JAIL), 'ip');

        $wanted = array_values(array_filter($shared, fn ($ip) => ! in_array($ip, $recidive) && ! $fail2ban->isIgnoredIp($ip, $ignored)));
        $current = array_column($fail2ban->getBans(Fail2ban::SHARED_JAIL), 'ip');

        $ban = array_values(array_diff($wanted, $current));
        $unban = array_values(array_diff($current, $wanted));

        if (count($ban) === 0 && count($unban) === 0) {
            $output->writeln(ucfirst($label).': shared bans are up to date ('.count($current).').');

            return;
        }

        if (! $dryRun && (! $fail2ban->setBans(Fail2ban::SHARED_JAIL, $ban) || ! $fail2ban->setBans(Fail2ban::SHARED_JAIL, $unban, false))) {
            $output->writeln('<error>'.ucfirst($label).': fail2ban could not change the shared bans.</error>');

            return;
        }

        $output->writeln(ucfirst($label).': '.($dryRun ? 'would ban ' : 'banned ').count($ban).', '.($dryRun ? 'would unban ' : 'unbanned ').count($unban).' addresses.');
    }

    /**
     * fail2ban which bans the scanners: of this server, or of every router in front of it. Servers without
     * the shared jail are skipped until monitor:install adds it.
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
            if (! $fail2ban->isInstalled() || ! $fail2ban->isRunning() || ! in_array(Fail2ban::SHARED_JAIL, $fail2ban->getJails())) {
                $output->writeln('<comment>'.ucfirst($label).' has no running jail '.Fail2ban::SHARED_JAIL.', run php vpsmanager monitor:install.</comment>');

                unset($servers[$label]);
            }
        }

        return $servers;
    }
}
