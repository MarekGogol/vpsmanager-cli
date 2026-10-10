<?php

namespace Gogol\VpsManagerCLI\Command\Monitor;

use Gogol\VpsManagerCLI\Helpers\Monitor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MonitorReportCommand extends Command
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('monitor:report')
            ->setDescription('Summary of the access log: addresses and urls which may need a ban, for an analysis by AI')
            ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Requests of the last hours, the log keeps a week', 24)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows of each list', 20)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON, e.g. for an analysis by AI');
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

        if (! glob(Monitor::LOG.'*')) {
            $output->writeln('<error>There is no access log, enable it with</error> php vpsmanager monitor:install');

            return Command::FAILURE;
        }

        $hours = max(1, (int) $input->getOption('hours'));
        $limit = max(1, (int) $input->getOption('limit'));

        $ignored = array_filter(vpsManager()->fail2ban()->getIgnoredIps(), fn ($ip) => ! str_contains($ip, '/'));

        $report = vpsManager()->monitor()->report(time() - $hours * 3600, $this->getBannedIps(), $ignored, $limit);

        if ($input->getOption('json')) {
            $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $output->writeln('<info>Requests since '.$report['since'].':</info> '.$report['requests'].' from '.$report['addresses'].' addresses ('.$report['banned_addresses_seen'].' of them banned now)');
        $output->writeln('Statuses: '.$this->inline($report['statuses']).' | errors: '.$this->inline($report['errors']));

        $this->table($output, 'Requests by host', ['Host', 'Requests'], array_map(null, array_keys($report['hosts']), $report['hosts']));
        $this->table($output, 'Addresses with failed requests, not banned', ['IP', 'Failed', 'Requests', 'Peak/min', 'Hosts', 'Failed paths'], array_map('array_values', $report['suspicious_addresses']));
        $this->table($output, 'Failed paths (candidates for vpsmanager/scanners.conf)', ['Path', 'Status', 'Requests', 'Addresses'], array_map('array_values', $report['failed_paths']));
        $this->table($output, 'Busiest addresses', ['IP', 'Peak/min', 'Requests', 'Failed', 'Banned'], array_map(fn ($row) => [...array_slice(array_values($row), 0, 4), $row['banned'] ? 'yes' : ''], $report['busiest_addresses']));
        $this->table($output, 'User agents of failed requests', ['User agent', 'Failed'], array_map(fn ($agent, $count) => [mb_strimwidth($agent, 0, 90, '…'), $count], array_keys($report['failing_agents']), $report['failing_agents']));

        $output->writeln('');
        $output->writeln('For an analysis by AI: <comment>php vpsmanager monitor:report --json</comment>');

        return Command::SUCCESS;
    }

    /**
     * Addresses banned in any jail of fail2ban now.
     *
     * @return array
     */
    protected function getBannedIps(): array
    {
        $servers = [vpsManager()->fail2ban()];

        // Behind a router the router bans the scanners
        if (($router = vpsManager()->router())->isEnabled() && $router->getDestination()) {
            $servers[] = $router->fail2ban();
        }

        $ips = [];

        foreach ($servers as $fail2ban) {
            if (! $fail2ban->isInstalled() || ! $fail2ban->isRunning()) {
                continue;
            }

            foreach ($fail2ban->getJails() as $jail) {
                foreach ($fail2ban->getBans($jail) as $ban) {
                    $ips[] = $ban['ip'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Render a titled table, nothing when there are no rows.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  string  $title
     * @param  array  $headers
     * @param  array  $rows
     * @return void
     */
    protected function table(OutputInterface $output, string $title, array $headers, array $rows): void
    {
        if (count($rows) === 0) {
            return;
        }

        $output->writeln('');
        $output->writeln('<comment>'.$title.'</comment>');

        (new Table($output))->setHeaders($headers)->setRows($rows)->render();
    }

    /**
     * Format key => count pairs on one line.
     *
     * @param  array  $counts
     * @return string
     */
    protected function inline(array $counts): string
    {
        return implode(', ', array_map(fn ($key, $count) => $key.' '.$count, array_keys($counts), $counts)) ?: '-';
    }
}
