<?php

namespace Gogol\VpsManagerCLI\Command\Backup;

use Exception;
use Gogol\VpsManagerCLI\Helpers\Restic;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackupRestoreCommand extends Command
{
    /**
     * Directory of the restored backups.
     *
     * @var string
     */
    const RESTORE_PATH = '/root/restore';

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('backup:restore')
            ->setDescription('Restore a database, website or directory from the backups, never over the live data')
            ->addArgument('type', InputArgument::REQUIRED, 'databases, www or dirs')
            ->addArgument('name', InputArgument::OPTIONAL, 'Name of the database or the website, a path for dirs')
            ->addOption('snapshot', null, InputOption::VALUE_REQUIRED, 'ID of the backup from backup:list --name, latest by default', 'latest')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'File or directory of the restored data, in '.self::RESTORE_PATH.' by default')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'Import the database into this new database instead of a file');
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

        $type = $input->getArgument('type');
        $name = $input->getArgument('name');

        if (! in_array($type, ['databases', 'www', 'dirs'])) {
            throw new Exception('Type must be databases, www or dirs.');
        }

        if ($type !== 'dirs' && ! $name) {
            throw new Exception('Please fill the name of the '.($type === 'databases' ? 'database' : 'website').'.');
        }

        $snapshot = $this->findSnapshot($type, $name, $input->getOption('snapshot'));
        $time = BackupListCommand::time($snapshot['time']);

        $output->writeln('Restoring the backup <comment>'.$snapshot['short_id'].'</comment> from <comment>'.$time->format('d.m.Y H:i').'</comment>.');

        if ($type === 'databases') {
            return $this->restoreDatabase($output, $snapshot, $name, $input->getOption('target'), $input->getOption('database'));
        }

        $target = $input->getOption('target') ?: self::RESTORE_PATH.'/'.$type.'/'.($type === 'www' ? $name : 'directories').'-'.$time->format('Y-m-d_H-i');

        return $this->restoreFiles($output, $snapshot, $target, $type === 'dirs' ? $name : null);
    }

    /**
     * Find the snapshot of a database, website or the directories.
     *
     * @param  string  $type
     * @param  string|null  $name
     * @param  string  $id  ID or its prefix, or latest
     * @return array
     *
     * @throws \Exception
     */
    private function findSnapshot(string $type, ?string $name, string $id): array
    {
        $backup = vpsManager()->backup();

        if (($snapshots = $backup->restic()->snapshots(['--tag', $type], $error)) === null) {
            throw new Exception('Backups could not be listed: '.Restic::errorMessage($error));
        }

        $groups = BackupListCommand::groupSnapshots($snapshots, (string) $backup->config('backup_www_path', '/var/www'));

        if ($type === 'dirs') {
            // Directories are one snapshot, a path picks the snapshots with it
            $items = array_merge(...array_values($groups['dirs'] ?? [[]]));
            $items = $name ? array_values(array_filter($items, fn ($item) => in_array(rtrim($name, '/'), $item['paths'] ?? []))) : $items;
            usort($items, fn ($a, $b) => strcmp($a['time'], $b['time']));
        } else {
            $items = $groups[$type][$name] ?? [];
        }

        if (! $items) {
            throw new Exception('No backup of '.($name ?: $type).' was found.');
        }

        if ($id === 'latest') {
            return end($items);
        }

        foreach ($items as $item) {
            if (str_starts_with($item['id'], $id)) {
                return $item;
            }
        }

        throw new Exception('Backup '.$id.' of '.($name ?: $type).' was not found, see backup:list --name.');
    }

    /**
     * Restore a database into a file or a new database.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  array  $snapshot
     * @param  string  $name
     * @param  string|null  $target
     * @param  string|null  $database
     * @return int
     *
     * @throws \Exception
     */
    private function restoreDatabase(OutputInterface $output, array $snapshot, string $name, ?string $target, ?string $database): int
    {
        $restic = vpsManager()->restic();
        $path = $snapshot['paths'][0];
        $dump = '{restic} dump '.escapeshellarg($snapshot['id']).' '.escapeshellarg($path);

        if ($database) {
            if (! preg_match('/^[a-zA-Z0-9_]+$/', $database)) {
                throw new Exception('Name of the new database may contain only letters, numbers and underscores.');
            }

            if (vpsManager()->mysql()->databaseExists($database)) {
                throw new Exception('Database '.$database.' exists already, the restore never overwrites a database.');
            }

            $optionFile = vpsManager()->backup()->createMysqlOptionFile();

            try {
                exec('mysql --defaults-extra-file='.escapeshellarg($optionFile).' -e '.escapeshellarg('CREATE DATABASE `'.$database.'`').' 2>&1', $result, $code);

                if ($code !== 0) {
                    throw new Exception('Database '.$database.' could not be created: '.implode(' ', $result));
                }

                $code = $restic->pipeline($dump.' | mysql --defaults-extra-file='.escapeshellarg($optionFile).' '.escapeshellarg($database), $error);
            } finally {
                @unlink($optionFile);
            }

            if ($code !== 0) {
                throw new Exception('Database could not be imported: '.Restic::errorMessage($error));
            }

            $output->writeln('<info>Database</info> <comment>'.$name.'</comment> <info>has been restored into the new database</info> <comment>'.$database.'</comment>.');

            return Command::SUCCESS;
        }

        $target = $target ?: self::RESTORE_PATH.'/databases/'.$name.'-'.BackupListCommand::time($snapshot['time'])->format('Y-m-d_H-i').'.sql';

        if (file_exists($target)) {
            throw new Exception('File '.$target.' exists already.');
        }

        exec('mkdir -p '.escapeshellarg(dirname($target)).' && chmod 700 '.escapeshellarg(self::RESTORE_PATH).' 2>/dev/null');

        if ($restic->pipeline('umask 077 && '.$dump.' > '.escapeshellarg($target), $error) !== 0) {
            @unlink($target);

            throw new Exception('Database could not be restored: '.Restic::errorMessage($error));
        }

        $output->writeln('<info>Database</info> <comment>'.$name.'</comment> <info>has been restored into</info> <comment>'.$target.'</comment>');

        return Command::SUCCESS;
    }

    /**
     * Restore a website or directories into a new directory.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  array  $snapshot
     * @param  string  $target
     * @param  string|null  $include  only this path of the snapshot
     * @return int
     *
     * @throws \Exception
     */
    private function restoreFiles(OutputInterface $output, array $snapshot, string $target, ?string $include): int
    {
        $target = rtrim($target, '/');

        if ($target === '' || in_array($target, array_map(fn ($path) => rtrim($path, '/'), $snapshot['paths']))) {
            throw new Exception('The restore never overwrites the live data, choose another target.');
        }

        if (is_dir($target) && count(scandir($target)) > 2) {
            throw new Exception('Directory '.$target.' is not empty.');
        }

        exec('mkdir -p '.escapeshellarg($target).' && chmod 700 '.escapeshellarg(self::RESTORE_PATH).' 2>/dev/null');

        $arguments = ['restore', $snapshot['id'], '--target', $target];

        if ($include) {
            array_push($arguments, '--include', rtrim($include, '/'));
        }

        if (vpsManager()->restic()->run($arguments, $result, $error) !== 0) {
            throw new Exception('Backup could not be restored: '.Restic::errorMessage($error));
        }

        $output->writeln('<info>Backup has been restored into</info> <comment>'.$target.'</comment> <info>with the original paths, e.g.</info> <comment>'.$target.($include ?: $snapshot['paths'][0]).'</comment>');

        return Command::SUCCESS;
    }
}
