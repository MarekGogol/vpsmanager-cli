<?php

namespace Gogol\VpsManagerCLI\Command\Monitor;

use Gogol\VpsManagerCLI\Helpers\Monitor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MonitorAccessLogCommand extends Command
{
    /**
     * Configuration file of the access log in the vpsmanager directory of NGINX.
     */
    const FILE = 'monitor.conf';

    /**
     * NGINX files of the access log fully managed by vpsmanager.
     */
    const MANAGED = ['vpsmanager/monitor.conf', 'conf.d/vpsmanager-monitor.conf'];

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('monitor:access-log')
            ->setDescription('Log requests of all hosts for a week, for the analysis of addresses and urls which should be banned')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list the hosts and files which would be changed')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Stop logging and delete the log');
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

        $nginx = vpsManager()->nginx();
        $monitor = vpsManager()->monitor();
        $dryRun = (bool) $input->getOption('dry-run');
        $remove = (bool) $input->getOption('remove');

        $written = $remove ? [] : $nginx->syncNginxSettings(self::MANAGED, $dryRun);

        foreach ($written as $file => $previous) {
            $output->writeln(($dryRun ? 'Would write' : 'Wrote').' <comment>'.$file.'</comment>'.($previous === null ? ' (new file)' : ' (new version)'));
        }

        $changes = [];

        foreach ($nginx->getEnabledHostPaths() as $path) {
            $conf = file_get_contents($path);

            // WordPress is logged too, it is the most attacked application
            $updated = $remove
                ? $nginx->restoreServerAccessLogOff($nginx->removeVpsManagerInclude($conf, self::FILE), self::FILE)
                : $nginx->disableServerAccessLogOff($nginx->addVpsManagerInclude($conf, self::FILE, false), self::FILE);

            if ($updated !== $conf) {
                $changes[$path] = [$conf, $updated];

                $output->writeln(($dryRun ? 'Would update' : 'Updating').' <comment>'.$path.'</comment>');
            }
        }

        $rotation = $remove ? [] : $monitor->writeFiles(true);

        foreach ($rotation as $path => $previous) {
            $output->writeln(($dryRun ? 'Would write' : 'Writing').' <comment>'.$path.'</comment>');
        }

        if ($dryRun) {
            return Command::SUCCESS;
        }

        if (count($changes) === 0 && count($written) === 0 && count($rotation) === 0 && ! $remove) {
            $output->writeln('<info>Access log is already up to date: '.Monitor::LOG.'</info>');

            return Command::SUCCESS;
        }

        if (! $remove) {
            $monitor->ensureLogDirectory();
            $monitor->writeFiles();
        }

        foreach ($changes as $path => [$conf, $updated]) {
            file_put_contents($path, $updated);
        }

        // Invalid configuration restores all hosts and files, so NGINX keeps running with the previous one
        if (! $nginx->reload()) {
            foreach ($changes as $path => [$conf, $updated]) {
                file_put_contents($path, $conf);
            }

            $nginx->restoreNginxSettings($written);

            $output->writeln('<error>NGINX configuration is not valid or NGINX could not be reloaded, previous configuration of all hosts has been restored.</error>');

            return Command::FAILURE;
        }

        if ($remove) {
            $monitor->removeFiles();

            foreach (glob(Monitor::LOG.'*') as $file) {
                @unlink($file);
            }

            $output->writeln('<info>Access log has been removed from '.count($changes).' hosts and deleted.</info>');

            return Command::SUCCESS;
        }

        $output->writeln('<info>Requests of all hosts are logged into '.Monitor::LOG.' ('.count($changes).' updated hosts).</info>');
        $output->writeln('Static files are not logged, secrets in query strings are masked. The log is rotated every hour when it');
        $output->writeln('grows over '.Monitor::MAX_SIZE.' and every day, rotated logs are compressed and deleted after a week.');
        $output->writeln('Summary for the analysis: <comment>php vpsmanager monitor:report</comment>');

        return Command::SUCCESS;
    }
}
