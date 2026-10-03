<?php

namespace Gogol\VpsManagerCLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class NginxScannersCommand extends Command
{
    /**
     * Configuration file with the rules against scanners, in the vpsmanager directory of NGINX.
     */
    const FILE = 'scanners.conf';

    /**
     * Files fully managed by vpsmanager, replaced on the server when a new version changes them.
     */
    const MANAGED = ['scanners.conf', 'scanners.html'];

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('nginx:scanners')
            ->setDescription('Answer requests of vulnerability scanners in NGINX for all hosts, without reaching PHP')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list the hosts which would be changed')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove the rules from all hosts');
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
        $dryRun = (bool) $input->getOption('dry-run');
        $remove = (bool) $input->getOption('remove');

        $written = $remove ? [] : $nginx->syncNginxSettings(self::MANAGED, $dryRun);

        foreach ($written as $file => $previous) {
            $output->writeln(($dryRun ? 'Would write' : 'Wrote').' <comment>vpsmanager/'.$file.'</comment>'.($previous === null ? ' (new file)' : ' (new version)'));
        }

        $changes = [];

        foreach ($nginx->getEnabledHostPaths() as $path) {
            $conf = file_get_contents($path);

            $updated = $remove
                ? $nginx->removeVpsManagerInclude($conf, self::FILE)
                : $nginx->addVpsManagerInclude($conf, self::FILE);

            if ($updated !== $conf) {
                $changes[$path] = [$conf, $updated];
            }

            // WordPress hosts keep their configuration, the rules would block their paths
            if (! $remove && str_contains($conf, 'vpsmanager/wordpress.conf')) {
                $output->writeln('Skipped WordPress sections of <comment>'.basename($path).'</comment>.');
            }
        }

        if (count($changes) === 0 && count($written) === 0) {
            $output->writeln('<info>All hosts are already up to date.</info>');

            return Command::SUCCESS;
        }

        foreach ($changes as $path => [$conf, $updated]) {
            $count = abs(substr_count($updated, 'vpsmanager/'.self::FILE) - substr_count($conf, 'vpsmanager/'.self::FILE));

            $output->writeln(($dryRun ? 'Would update' : 'Updating').' <comment>'.$path.'</comment> ('.$count.' server sections)');
        }

        if ($dryRun) {
            return Command::SUCCESS;
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

        $output->writeln('<info>'.($remove ? 'Rules against scanners have been removed from' : 'Rules against scanners are active in').' '.count($changes).' updated hosts and NGINX has been reloaded.</info>');

        return Command::SUCCESS;
    }
}
