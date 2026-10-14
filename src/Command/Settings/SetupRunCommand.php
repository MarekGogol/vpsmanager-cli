<?php

namespace Gogol\VpsManagerCLI\Command\Settings;

use Gogol\VpsManagerCLI\Helpers\Settings\JournaldSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\LaravelLogsSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\MysqlSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\PhpFpmSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\RebootSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\Setting;
use Gogol\VpsManagerCLI\Helpers\Settings\SshSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\StunnelSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\SwappinessSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\SwapRefreshSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\SwapSetting;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class SetupRunCommand extends Command
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('setup:run')
            ->setDescription('Set the recommended settings of the server: swap, vm.swappiness, swap refresh, memory of MySQL, stunnel on localhost, SSH by keys only')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Apply the changes, without it only the table of the settings is printed')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Only the given settings separated by commas: '.implode(',', array_keys($this->settings())))
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'With --execute apply without questions, the risky steps (SSH, restart of MySQL) included');
    }

    /**
     * All settings by their keys, in the order of their application.
     *
     * @return array<string, \Gogol\VpsManagerCLI\Helpers\Settings\Setting>
     */
    protected function settings(): array
    {
        $settings = [];

        foreach ([new SwapSetting(), new SwappinessSetting(), new SwapRefreshSetting(), new JournaldSetting(), new LaravelLogsSetting(), new PhpFpmSetting(), new MysqlSetting(), new StunnelSetting(), new SshSetting(), new RebootSetting()] as $setting) {
            $settings[$setting->key()] = $setting;
        }

        return $settings;
    }

    /**
     * Settings chosen by --only.
     *
     * @param  string|null  $only
     * @return array<string, \Gogol\VpsManagerCLI\Helpers\Settings\Setting>
     *
     * @throws \InvalidArgumentException
     */
    protected function chosen(?string $only): array
    {
        $settings = $this->settings();

        if (! $only) {
            return $settings;
        }

        $keys = array_filter(array_map('trim', explode(',', $only)));

        if ($unknown = array_diff($keys, array_keys($settings))) {
            throw new InvalidArgumentException('Unknown settings '.implode(', ', $unknown).', use '.implode(', ', array_keys($settings)).'.');
        }

        return array_intersect_key($settings, array_flip($keys));
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

        $settings = $this->chosen($input->getOption('only'));
        $yes = (bool) $input->getOption('yes');

        $this->printTable($output, $settings);

        $changes = array_filter($settings, fn (Setting $setting) => $setting->plan() !== []);

        if (! $changes) {
            $notes = array_filter($settings, fn (Setting $setting) => $setting->warnings() !== []);
            $output->writeln($notes ? '<info>Nothing to change</info>, see the notes.' : '<info>Everything is up to date.</info>');

            return Command::SUCCESS;
        }

        // Nothing is changed without --execute
        if (! $input->getOption('execute')) {
            $output->writeln('Nothing has been changed, apply the changes with <comment>setup:run --execute</comment>.');

            return Command::SUCCESS;
        }

        $confirm = function (string $question, bool $default) use ($input, $output, $yes) {
            if ($yes) {
                return true;
            }

            return (bool) $this->getHelper('question')->ask($input, $output, new ConfirmationQuestion(
                '<question>'.$question.'</question> ('.($default ? 'Y/n' : 'y/N').') ', $default,
            ));
        };

        if (! $confirm('Apply the changes of '.implode(', ', array_keys($changes)).'?', true)) {
            return Command::SUCCESS;
        }

        $failed = [];

        foreach ($changes as $key => $setting) {
            $output->writeln('');
            $output->writeln('<info>'.$key.'</info>: '.implode(', ', $setting->plan()));

            if ($setting->isRisky() && ! $confirm('Change '.$key.' ('.implode(', ', $setting->plan()).')?', false)) {
                $output->writeln('Skipped.');

                continue;
            }

            if (! $setting->apply($output, $confirm)) {
                $failed[] = $key;
            }
        }

        $output->writeln('');

        if ($failed) {
            $output->writeln('<error>Failed: '.implode(', ', $failed).'.</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Done.</info> Run setup:run to check the settings.');

        return Command::SUCCESS;
    }

    /**
     * Print the table of the settings and their warnings.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  array  $settings
     * @return void
     */
    protected function printTable(OutputInterface $output, array $settings): void
    {
        $table = new Table($output);
        $table->setHeaders(['Setting', 'Current', 'Recommended', 'Action']);

        foreach ($settings as $key => $setting) {
            $table->addRow([$key, $setting->current(), $setting->recommended(), implode(', ', $setting->plan()) ?: ($setting->warnings() ? 'see the notes' : 'up to date')]);
        }

        $table->render();

        foreach ($settings as $key => $setting) {
            foreach ($setting->warnings() as $warning) {
                $output->writeln('<comment>'.$key.': '.$warning.'</comment>');
            }
        }
    }
}
