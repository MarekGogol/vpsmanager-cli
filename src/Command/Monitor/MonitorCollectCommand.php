<?php

namespace Gogol\VpsManagerCLI\Command\Monitor;

use Gogol\VpsManagerCLI\Helpers\MonitorAgent;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MonitorCollectCommand extends Command
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('monitor:collect')
            ->setDescription('Send the metrics, the requests of the hosts and the bans of this server to the monitor, ban the addresses shared by the monitor')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only print what would be sent, nothing is sent, banned or remembered')
            ->addOption('spread', null, InputOption::VALUE_NONE, 'Wait seconds derived from the hostname first, used by the cron');
    }

    /**
     * Execute the command. The monitor is optional: when it does not answer, the windows of the requests wait for
     * the next run (an hour at most) and the bans of this server work as before.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        vpsManager()->bootConsole($output, $input, $this->getHelper('question'));
        vpsManager()->monitor()->useServerTimezone();

        $dryRun = (bool) $input->getOption('dry-run');
        $agent = vpsManager()->monitorAgent();

        if (! $agent->getUrl() || ! $agent->getToken()) {
            $output->writeln('<comment>The monitor is not configured, set its url and the token of this server with php vpsmanager monitor:install</comment>');

            return Command::SUCCESS;
        }

        if (! is_dir(dirname(MonitorAgent::LOCK))) {
            mkdir(dirname(MonitorAgent::LOCK), 0700, true);
        }

        $lock = fopen(MonitorAgent::LOCK, 'c');

        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $output->writeln('<comment>monitor:collect is running already.</comment>');

            return Command::SUCCESS;
        }

        if ($input->getOption('spread')) {
            sleep($agent->getSpreadSeconds());
        }

        $state = $agent->loadState();
        $servers = $agent->getFail2bans($output);

        $metrics = $agent->collectMetrics();
        $windows = $agent->collectHosts($state, time());
        [$bans, $currentBans] = $agent->getNewBans($servers, $state);

        $body = array_filter([
            'hostname' => (string) gethostname(),
            'version' => MonitorAgent::VERSION,
            'metrics' => $metrics ?: null,
            'hosts' => $windows,
            'blocked_ips' => $bans,
            'shared_ips_since' => $state['shared_since'] ?? null,
        ], fn ($value) => $value !== null && $value !== []);

        $output->writeln(($dryRun ? 'Would send' : 'Sending').': '.($metrics ? count($metrics).' metrics' : 'no metrics').', '
            .count($windows).' windows of '.count(array_unique(array_column($windows, 'host'))).' hosts, '
            .count($bans).' new bans.');

        if ($dryRun) {
            $output->writeln(json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::VERBOSITY_VERBOSE);

            return Command::SUCCESS;
        }

        if (($answer = $agent->request('POST', 'collect', $body, $error)) === null || empty($answer['success'])) {
            // Positions in the logs move on, the counted windows wait in the state
            $agent->saveState($state);

            $output->writeln('<comment>The monitor did not answer ('.($error ?? 'no success').'), the windows are sent with the next run, shared bans are kept as they are.</comment>');

            return Command::FAILURE;
        }

        $agent->markWindowsSent($state, $windows);
        $state['reported'] = $currentBans;

        if (isset($answer['shared_ips'])) {
            $agent->mergeSharedBans($state, $answer['shared_ips']);
        }

        $agent->saveState($state);

        $output->writeln('The monitor shares '.count($state['shared'] ?? []).' addresses ('.count($answer['shared_ips']['data'] ?? []).' changed).');

        foreach ($servers as $label => $fail2ban) {
            $agent->applySharedBans($output, $label, $fail2ban, $state['shared'] ?? []);
        }

        return Command::SUCCESS;
    }
}
