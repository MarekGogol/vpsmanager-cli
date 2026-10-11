<?php

namespace Gogol\VpsManagerCLI\Command\Monitor;

use Gogol\VpsManagerCLI\Helpers\Monitor;
use Gogol\VpsManagerCLI\Helpers\RequestStats;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MonitorListenCommand extends Command
{
    /**
     * Seconds of the sliding window of the requests per minute.
     */
    const WINDOW = 60;

    /**
     * Bytes of the end of the logs read at the start, so the first minute is not empty.
     */
    const BACKFILL = 4 * 1024 * 1024;

    /**
     * Requests of the hosts by second: host => second => [requests, errors, duration, blocked].
     *
     * @var array
     */
    protected $seconds = [];

    /**
     * Requests of the hosts since the start of the command: host => [requests, blocked].
     *
     * @var array
     */
    protected $totals = [];

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('monitor:listen')
            ->setDescription('Live requests of the hosts from the access log: requests per minute and per second')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Only hosts containing the text')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Show unknown hosts by their name instead of one row "other"')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between the refreshes', 2)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows of the table', 30)
            ->addOption('duration', null, InputOption::VALUE_REQUIRED, 'Stop after the seconds, 0 runs until Ctrl+C', 0);
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

        if (! file_exists(Monitor::LOG)) {
            $output->writeln('<error>There is no access log, enable it with</error> php vpsmanager monitor:install');

            return Command::FAILURE;
        }

        vpsManager()->monitor()->useServerTimezone();

        $stats = vpsManager()->requestStats();
        $hosts = $stats->getHosts();
        $filter = $input->getOption('host');
        $all = (bool) $input->getOption('all');
        $interval = max(1, (int) $input->getOption('interval'));
        $limit = max(1, (int) $input->getOption('limit'));
        $duration = max(0, (int) $input->getOption('duration'));

        $start = time();
        $positions = [
            Monitor::LOG => $stats->getEndPosition(Monitor::LOG, self::BACKFILL),
            RequestStats::SCANNERS_LOG => $stats->getEndPosition(RequestStats::SCANNERS_LOG, self::BACKFILL),
        ];

        $section = $output instanceof ConsoleOutputInterface ? $output->section() : $output;

        while (true) {
            foreach ($positions as $path => &$position) {
                foreach ($stats->readNewLines($path, $position) as $line) {
                    $request = $path === Monitor::LOG ? $stats->parseRequest($line) : $stats->parseBlocked($line);

                    if (! $request || $request['time'] <= time() - self::WINDOW && $request['time'] < $start) {
                        continue;
                    }

                    $host = $stats->normalizeHost($request['host'], $hosts);
                    $host = $all && $host === RequestStats::OTHER ? $stats->stripHost($request['host']) : $host;

                    if ($filter !== null && ! str_contains($host, $filter)) {
                        continue;
                    }

                    $this->add($host, $request, $path !== Monitor::LOG, $request['time'] >= $start);
                }
            }

            unset($position);

            $this->render($section, $start, $limit);

            if ($duration && time() - $start >= $duration) {
                return Command::SUCCESS;
            }

            sleep($interval);
        }
    }

    /**
     * Count the request into its second and into the totals since the start.
     *
     * @param  string  $host
     * @param  array  $request  parsed line
     * @param  bool  $blocked  request blocked by the rules against scanners
     * @param  bool  $sinceStart  request made after the start of the command
     * @return void
     */
    protected function add(string $host, array $request, bool $blocked, bool $sinceStart): void
    {
        $second = &$this->seconds[$host][$request['time']];
        $second ??= [0, 0, 0.0, 0];

        if ($blocked) {
            $second[3]++;
        } else {
            $second[0]++;
            $second[1] += $request['status'] >= 500 ? 1 : 0;
            $second[2] += $request['duration'];
        }

        if ($sinceStart) {
            $this->totals[$host] ??= [0, 0];
            $this->totals[$host][$blocked ? 1 : 0]++;
        }
    }

    /**
     * Render the table of the hosts over the previous one.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  int  $start
     * @param  int  $limit
     * @return void
     */
    protected function render(OutputInterface $output, int $start, int $limit): void
    {
        $now = time();
        $elapsed = max(1, $now - $start);
        $rows = [];
        $sum = ['requests' => 0, 'errors' => 0, 'duration' => 0.0, 'blocked' => 0, 'total' => 0, 'total_blocked' => 0];

        foreach (array_keys($this->seconds + $this->totals) as $host) {
            $window = ['requests' => 0, 'errors' => 0, 'duration' => 0.0, 'blocked' => 0];

            foreach ($this->seconds[$host] ?? [] as $time => [$requests, $errors, $duration, $blocked]) {
                if ($time <= $now - self::WINDOW) {
                    unset($this->seconds[$host][$time]);

                    continue;
                }

                $window['requests'] += $requests;
                $window['errors'] += $errors;
                $window['duration'] += $duration;
                $window['blocked'] += $blocked;
            }

            [$total, $totalBlocked] = $this->totals[$host] ?? [0, 0];

            foreach ($window as $key => $value) {
                $sum[$key] += $value;
            }

            $sum['total'] += $total;
            $sum['total_blocked'] += $totalBlocked;

            $rows[$host] = $this->row($host, $window, $total, $totalBlocked, $elapsed);
        }

        uksort($rows, fn ($a, $b) => $rows[$b][1] <=> $rows[$a][1] ?: $rows[$b][4] <=> $rows[$a][4] ?: strcmp($a, $b));

        $hidden = count($rows) - $limit;
        $rows = array_values(array_slice($rows, 0, $limit));

        $buffer = new BufferedOutput($output->getVerbosity(), $output->isDecorated());
        $buffer->writeln('<info>Requests of the hosts</info> since '.date('H:i:s', $start).' ('.$this->formatElapsed($elapsed).'), refreshed '.date('H:i:s', $now));
        $buffer->writeln('Static files are not logged, NGINX writes the log every 5 seconds. Per minute = the last '.self::WINDOW.' seconds.');

        $table = (new Table($buffer))
            ->setHeaders(['Host', 'Req/min', 'Req/s', 'Avg req/s', 'Total', '5xx/min', 'Avg ms', 'Blocked/min', 'Blocked'])
            ->setRows([...$rows, new TableSeparator, $this->row('All hosts', $sum, $sum['total'], $sum['total_blocked'], $elapsed)]);

        // Numbers are aligned to the right
        foreach (range(1, 8) as $column) {
            $table->setColumnStyle($column, (new TableStyle)->setPadType(STR_PAD_LEFT));
        }

        $table->render();

        if ($hidden > 0) {
            $buffer->writeln($hidden.' more hosts, filter them with --host or show them with --limit');
        }

        method_exists($output, 'overwrite') ? $output->overwrite($buffer->fetch()) : $output->write($buffer->fetch());
    }

    /**
     * Row of the table.
     *
     * @param  string  $host
     * @param  array  $window  requests of the last minute
     * @param  int  $total  requests since the start
     * @param  int  $totalBlocked  blocked requests since the start
     * @param  int  $elapsed  seconds since the start
     * @return array
     */
    protected function row(string $host, array $window, int $total, int $totalBlocked, int $elapsed): array
    {
        return [
            $host,
            $window['requests'],
            number_format($window['requests'] / self::WINDOW, 2),
            number_format($total / $elapsed, 2),
            $total,
            $window['errors'] ?: '',
            $window['requests'] ? round($window['duration'] / $window['requests'] * 1000) : '',
            $window['blocked'] ?: '',
            $totalBlocked ?: '',
        ];
    }

    /**
     * Format the seconds as 1h 02m 03s.
     *
     * @param  int  $seconds
     * @return string
     */
    protected function formatElapsed(int $seconds): string
    {
        return ($seconds >= 3600 ? intdiv($seconds, 3600).'h ' : '').sprintf('%02dm %02ds', intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
