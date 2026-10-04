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
            ->setDescription('Answer requests of vulnerability scanners in NGINX for all hosts and ban the scanners with fail2ban')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list the hosts and files which would be changed')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove the rules from all hosts and the fail2ban jails');
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

        $dryRun = (bool) $input->getOption('dry-run');
        $remove = (bool) $input->getOption('remove');

        if (! $this->updateNginx($output, $dryRun, $remove)) {
            return Command::FAILURE;
        }

        $output->writeln('');

        return $this->updateFail2ban($output, $dryRun, $remove) ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Add the rules to all hosts (or remove them) and reload NGINX.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  bool  $dryRun
     * @param  bool  $remove
     * @return bool
     */
    protected function updateNginx(OutputInterface $output, bool $dryRun, bool $remove): bool
    {
        $nginx = vpsManager()->nginx();

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
            $output->writeln('<info>NGINX hosts are already up to date.</info>');

            return true;
        }

        foreach ($changes as $path => [$conf, $updated]) {
            $count = abs(substr_count($updated, 'vpsmanager/'.self::FILE) - substr_count($conf, 'vpsmanager/'.self::FILE));

            $output->writeln(($dryRun ? 'Would update' : 'Updating').' <comment>'.$path.'</comment> ('.$count.' server sections)');
        }

        if ($dryRun) {
            return true;
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

            return false;
        }

        $output->writeln('<info>'.($remove ? 'Rules against scanners have been removed from' : 'Rules against scanners are active in').' '.count($changes).' updated hosts and NGINX has been reloaded.</info>');

        return true;
    }

    /**
     * Ban the scanners logged by NGINX with fail2ban (or remove the jails) and reload fail2ban.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  bool  $dryRun
     * @param  bool  $remove
     * @return bool
     */
    protected function updateFail2ban(OutputInterface $output, bool $dryRun, bool $remove): bool
    {
        $fail2ban = vpsManager()->fail2ban();

        if (! $fail2ban->isInstalled()) {
            $output->writeln('<comment>fail2ban is not installed, scanners are not banned. Install it with</comment> apt install fail2ban');

            return true;
        }

        $written = $remove
            ? $fail2ban->removeScannersFiles($dryRun)
            : $fail2ban->writeFiles($fail2ban->getScannersFiles(), $dryRun);

        foreach ($written as $path => $previous) {
            $action = $dryRun ? ($remove ? 'Would remove' : 'Would write') : ($remove ? 'Removed' : 'Wrote');

            $output->writeln($action.' <comment>'.$path.'</comment>');
        }

        if (count($written) === 0) {
            $output->writeln('<info>fail2ban configuration is already up to date.</info>');
        }

        // fail2ban does not start without the log of the default sshd jail
        if (! $fail2ban->hasAuthLog()) {
            $output->writeln(($dryRun ? 'Would install' : 'Installing').' <comment>rsyslog</comment>, there is no /var/log/auth.log for the sshd jail of fail2ban.');

            if (! $dryRun && ! $fail2ban->installAuthLog()) {
                $output->writeln('<error>rsyslog could not be installed, fail2ban does not start without /var/log/auth.log.</error>');

                return false;
            }
        }

        if ($dryRun) {
            return true;
        }

        if (! $remove) {
            $fail2ban->ensureScannersLog();
        }

        // Invalid configuration restores all files, fail2ban keeps running with the previous one
        if (count($written) && ! $fail2ban->test()) {
            $fail2ban->restoreFiles($written);

            $output->writeln('<error>fail2ban configuration is not valid, previous configuration has been restored.</error>');

            return false;
        }

        // Changed jails need a restart: reload flushes the bans of a changed action, the start applies them again
        if (! (count($written) ? $fail2ban->restart() : $fail2ban->reload())) {
            $output->writeln('<error>fail2ban could not be started or reloaded, check</error> journalctl -u fail2ban');

            return false;
        }

        if (count($written)) {
            $output->writeln('fail2ban has been restarted, bans of the database are applied with the current configuration.');
        }

        if ($remove) {
            $output->writeln('<info>fail2ban jails against scanners have been removed.</info>');

            return true;
        }

        $output->writeln('<info>fail2ban bans scanners for all websites of the server (jail '.$fail2ban::SCANNERS_JAIL.').</info>');
        $output->writeln($fail2ban->status($fail2ban::SCANNERS_JAIL) ?: '');

        return true;
    }
}
