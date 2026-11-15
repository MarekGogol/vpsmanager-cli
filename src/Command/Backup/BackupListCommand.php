<?php

namespace Gogol\VpsManagerCLI\Command\Backup;

use Carbon\Carbon;
use Exception;
use Gogol\VpsManagerCLI\Helpers\Backup;
use Gogol\VpsManagerCLI\Helpers\Restic;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackupListCommand extends Command
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('backup:list')
            ->setDescription('List the backups: the latest backup of each database, website and the directories, or all backups of one of them')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Only one type: databases, www or dirs')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'All backups of one database or website');
    }

    /**
     * Execute the command.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return int
     *
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        vpsManager()->bootConsole($output);

        $backup = vpsManager()->backup();

        if (! $backup->isConfigured()) {
            throw new Exception('Backups are not configured, run "php vpsmanager backup:setup".');
        }

        if (($snapshots = $backup->restic()->snapshots([], $error)) === null) {
            throw new Exception('Backups could not be listed: '.Restic::errorMessage($error));
        }

        $groups = self::groupSnapshots($snapshots, (string) $backup->config('backup_www_path', '/var/www'));

        if ($type = $input->getOption('type')) {
            $groups = array_intersect_key($groups, [$type => true]);
        }

        if (($name = $input->getOption('name')) !== null) {
            $this->listOne($output, $groups, $name);
        } else {
            $this->listLatest($output, $groups);
        }

        if ($backup->restic()->run(['stats', '--json', '--mode', 'raw-data'], $stats) === 0 && ($size = json_decode($stats, true)['total_size'] ?? null)) {
            $output->writeln('Size of the repository: <comment>'.self::formatBytes($size).'</comment>');
        }

        return Command::SUCCESS;
    }

    /**
     * Group the snapshots by their type and name, newest last.
     *
     * @param  array  $snapshots
     * @param  string  $wwwPath
     * @return array
     */
    public static function groupSnapshots(array $snapshots, string $wwwPath): array
    {
        $groups = [];

        foreach ($snapshots as $snapshot) {
            [$type, $name] = Backup::describeSnapshot($snapshot, $wwwPath);

            $groups[$type][$name][] = $snapshot;
        }

        foreach ($groups as &$names) {
            ksort($names);

            foreach ($names as &$items) {
                usort($items, fn ($a, $b) => strcmp($a['time'], $b['time']));
            }

            unset($items);
        }

        unset($names);

        return $groups;
    }

    /**
     * Write the latest backup of each database, website and the directories.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  array  $groups
     * @return void
     */
    private function listLatest(OutputInterface $output, array $groups): void
    {
        $table = new Table($output);
        $table->setHeaders(['Type', 'Name', 'Backups', 'Latest', 'Age', 'Size']);

        foreach ($groups as $type => $names) {
            foreach ($names as $name => $items) {
                $latest = end($items);
                $time = self::time($latest['time']);

                $table->addRow([$type, $name, count($items), $time->format('d.m.Y H:i'), $time->diffForHumans(), self::formatBytes($latest['summary']['total_bytes_processed'] ?? null)]);
            }
        }

        $table->render();
    }

    /**
     * Write all backups of one database, website or the directories.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  array  $groups
     * @param  string  $name
     * @return void
     */
    private function listOne(OutputInterface $output, array $groups, string $name): void
    {
        $table = new Table($output);
        $table->setHeaders(['Type', 'ID', 'Time', 'Size', 'Added']);

        foreach ($groups as $type => $names) {
            foreach (array_reverse($names[$name] ?? []) as $snapshot) {
                $table->addRow([
                    $type,
                    $snapshot['short_id'] ?? substr($snapshot['id'], 0, 8),
                    self::time($snapshot['time'])->format('d.m.Y H:i'),
                    self::formatBytes($snapshot['summary']['total_bytes_processed'] ?? null),
                    self::formatBytes($snapshot['summary']['data_added'] ?? null),
                ]);
            }
        }

        $table->render();
    }

    /**
     * Get the time of a snapshot in the timezone of the backups.
     *
     * @param  string  $time
     * @return \Carbon\Carbon
     */
    public static function time(string $time): Carbon
    {
        return Carbon::parse($time)->setTimezone('Europe/Bratislava');
    }

    /**
     * Format bytes for humans.
     *
     * @param  int|float|null  $bytes
     * @return string
     */
    public static function formatBytes($bytes): string
    {
        return Backup::formatBytes($bytes);
    }
}
