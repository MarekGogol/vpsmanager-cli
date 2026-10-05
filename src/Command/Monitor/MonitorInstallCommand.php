<?php

namespace Gogol\VpsManagerCLI\Command\Monitor;

use Gogol\VpsManagerCLI\Helpers\Monitor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MonitorInstallCommand extends Command
{
    /**
     * Configuration file with the rules against scanners, in the vpsmanager directory of NGINX.
     */
    const FILE = 'scanners.conf';

    /**
     * Rules against other .php files than index.php, included after FILE in sections without a password.
     */
    const PHP_FILE = 'scanners-php.conf';

    /**
     * Files fully managed by vpsmanager, replaced on the server when a new version changes them.
     */
    const MANAGED = ['vpsmanager/scanners.conf', 'vpsmanager/scanners-php.conf', 'vpsmanager/scanners.html', 'conf.d/vpsmanager-scanners.conf'];

    /**
     * Configuration file of the access log in the vpsmanager directory of NGINX.
     */
    const ACCESS_LOG_FILE = 'monitor.conf';

    /**
     * NGINX files of the access log fully managed by vpsmanager.
     */
    const ACCESS_LOG_MANAGED = ['vpsmanager/monitor.conf', 'conf.d/vpsmanager-monitor.conf'];

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('monitor:install')
            ->setDescription('Install the monitor: NGINX rules against scanners, fail2ban jails which ban them and a week of access log')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list the hosts and files which would be changed')
            ->addOption('without-access-log', null, InputOption::VALUE_NONE, 'Do not install the access log')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'Remove the monitor: rules, fail2ban jails and the access log with its files');
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

        $output->writeln('<comment>Rules against scanners</comment>');

        if (! $this->updateNginx($output, $dryRun, $remove)) {
            return Command::FAILURE;
        }

        if (! $input->getOption('without-access-log')) {
            $output->writeln('');
            $output->writeln('<comment>Access log</comment>');

            if (! $this->updateAccessLog($output, $dryRun, $remove)) {
                return Command::FAILURE;
            }
        }

        $output->writeln('');
        $output->writeln('<comment>fail2ban</comment>');

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

        // Removed files are restored like written ones when the configuration is not valid
        $written = $remove ? $nginx->removeNginxSettings(self::MANAGED, $dryRun) : $nginx->syncNginxSettings(self::MANAGED, $dryRun);

        $this->writeFiles($output, $written, $dryRun, $remove);

        $changes = [];

        foreach ($nginx->getEnabledHostPaths() as $path) {
            $conf = file_get_contents($path);

            $updated = $remove
                ? $nginx->removeVpsManagerInclude($nginx->removeVpsManagerInclude($conf, self::PHP_FILE), self::FILE)
                : $nginx->syncVpsManagerIncludeAfter($nginx->addVpsManagerInclude($conf, self::FILE), self::PHP_FILE, self::FILE);

            if ($updated !== $conf) {
                $changes[$path] = [$conf, $updated];
            }

            // WordPress hosts keep their configuration, the rules would block their paths
            if (! $remove && str_contains($conf, 'vpsmanager/wordpress.conf')) {
                $output->writeln('Skipped WordPress sections of <comment>'.basename($path).'</comment>.');
            }

            // Tools behind a password (phpMyAdmin, adminer) serve their own PHP files
            if (! $remove && substr_count($updated, 'vpsmanager/'.self::FILE.';') > substr_count($updated, 'vpsmanager/'.self::PHP_FILE.';')) {
                $output->writeln('Sections of <comment>'.basename($path).'</comment> protected by a password serve all .php files.');
            }
        }

        if (count($changes) === 0 && count($written) === 0) {
            $output->writeln('<info>NGINX hosts are already up to date.</info>');

            return true;
        }

        foreach ($changes as $path => [$conf, $updated]) {
            $output->writeln(($dryRun ? 'Would update' : 'Updating').' <comment>'.$path.'</comment> ('.implode(', ', array_filter([
                $this->countIncludes($conf, $updated, self::FILE, 'server sections'),
                $this->countIncludes($conf, $updated, self::PHP_FILE, 'with rules against .php files'),
            ])).')');
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
     * Print the NGINX files written or removed by the command.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  array  $files  relative path => previous content (null for a new file)
     * @param  bool  $dryRun
     * @param  bool  $remove
     * @return void
     */
    protected function writeFiles(OutputInterface $output, array $files, bool $dryRun, bool $remove): void
    {
        foreach ($files as $file => $previous) {
            $output->writeln($remove
                ? ($dryRun ? 'Would remove' : 'Removed').' <comment>'.$file.'</comment>'
                : ($dryRun ? 'Would write' : 'Wrote').' <comment>'.$file.'</comment>'.($previous === null ? ' (new file)' : ' (new version)'));
        }
    }

    /**
     * Describe the number of added or removed includes of the file, null when it did not change.
     *
     * @param  string  $conf
     * @param  string  $updated
     * @param  string  $file
     * @param  string  $label
     * @return string|null
     */
    protected function countIncludes(string $conf, string $updated, string $file, string $label): ?string
    {
        $count = substr_count($updated, 'vpsmanager/'.$file.';') - substr_count($conf, 'vpsmanager/'.$file.';');

        return $count === 0 ? null : sprintf('%+d %s', $count, $label);
    }

    /**
     * Log requests of all hosts for a week (or remove the log) and reload NGINX.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  bool  $dryRun
     * @param  bool  $remove
     * @return bool
     */
    protected function updateAccessLog(OutputInterface $output, bool $dryRun, bool $remove): bool
    {
        $nginx = vpsManager()->nginx();
        $monitor = vpsManager()->monitor();
        $written = $remove ? $nginx->removeNginxSettings(self::ACCESS_LOG_MANAGED, $dryRun) : $nginx->syncNginxSettings(self::ACCESS_LOG_MANAGED, $dryRun);

        $this->writeFiles($output, $written, $dryRun, $remove);

        $changes = [];

        foreach ($nginx->getEnabledHostPaths() as $path) {
            $conf = file_get_contents($path);

            // WordPress is logged too, it is the most attacked application
            $updated = $remove
                ? $nginx->restoreServerAccessLogOff($nginx->removeVpsManagerInclude($conf, self::ACCESS_LOG_FILE), self::ACCESS_LOG_FILE)
                : $nginx->disableServerAccessLogOff($nginx->addVpsManagerInclude($conf, self::ACCESS_LOG_FILE, false), self::ACCESS_LOG_FILE);

            if ($updated !== $conf) {
                $changes[$path] = [$conf, $updated];

                $output->writeln(($dryRun ? 'Would update' : 'Updating').' <comment>'.$path.'</comment>');
            }
        }

        $rotation = $remove ? [] : $monitor->writeFiles(true);

        foreach ($rotation as $path => $previous) {
            $output->writeln(($dryRun ? 'Would write' : 'Writing').' <comment>'.$path.'</comment>');
        }

        // The reload lets the workers of NGINX open the log again in the fixed directory
        $directory = ! $remove && $monitor->ensureLogDirectory(true);

        if ($directory) {
            $output->writeln(($dryRun ? 'Would fix' : 'Fixing').' <comment>'.dirname(Monitor::LOG).'</comment> (0755, workers of NGINX open the log after the rotation)');
        }

        if ($dryRun) {
            return true;
        }

        if (count($changes) === 0 && count($written) === 0 && count($rotation) === 0 && ! $directory && ! $remove) {
            $output->writeln('<info>Access log is already up to date: '.Monitor::LOG.'</info>');

            return true;
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

            return false;
        }

        if ($remove) {
            $monitor->removeFiles();

            foreach (glob(Monitor::LOG.'*') as $file) {
                @unlink($file);
            }

            $output->writeln('<info>Access log has been removed from '.count($changes).' hosts and deleted.</info>');

            return true;
        }

        $output->writeln('<info>Requests of all hosts are logged into '.Monitor::LOG.' ('.count($changes).' updated hosts).</info>');
        $output->writeln('Static files are not logged, secrets in query strings are masked. The log is rotated every hour when it');
        $output->writeln('grows over '.Monitor::MAX_SIZE.' and every day, rotated logs are compressed and deleted after a week.');
        $output->writeln('Summary for the analysis: <comment>php vpsmanager monitor:report</comment>');

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
