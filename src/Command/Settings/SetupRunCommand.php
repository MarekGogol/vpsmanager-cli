<?php

namespace Gogol\VpsManagerCLI\Command\Settings;

use Gogol\VpsManagerCLI\Helpers\Settings\AptKeysSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\JournaldSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\LaravelLogsSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\MysqlSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\PhpFpmSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\RebootSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\Setting;
use Gogol\VpsManagerCLI\Helpers\Settings\SshSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\StunnelSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\SwappinessSetting;
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
            ->setDescription('Print the recommended settings of the server and apply them with --execute: swap, swappiness, journald, rotation of Laravel logs, PHP-FPM pools, MySQL, stunnel, SSH, keys of APT')
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

        foreach ([new SwapSetting(), new SwappinessSetting(), new JournaldSetting(), new LaravelLogsSetting(), new PhpFpmSetting(), new MysqlSetting(), new StunnelSetting(), new SshSetting(), new AptKeysSetting(), new RebootSetting()] as $setting) {
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

        // Directories of the backups are named by the time of the server
        vpsManager()->monitor()->useServerTimezone();

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
            $output->writeln('<info>'.$key.'</info>: '.$this->action($setting->plan()));

            if ($setting->isRisky() && ! $confirm('Change '.$key.' ('.$this->action($setting->plan()).')?', false)) {
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
     * Text of the column Action: the changes, or their count when they are long.
     *
     * @param  array  $plan
     * @return string
     */
    protected function action(array $plan): string
    {
        $text = implode(', ', $plan);

        return mb_strlen($text) > 60 ? count($plan).' changes, see below' : $text;
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

        $long = [];

        foreach ($settings as $key => $setting) {
            $plan = $setting->plan();

            if ($this->action($plan) !== implode(', ', $plan)) {
                $long[$key] = $plan;
            }

            $table->addRow([$key, $setting->current(), $setting->recommended(), $this->action($plan) ?: ($setting->warnings() ? 'see the notes' : 'up to date')]);
        }

        $table->render();

        // A long plan is listed under the table, the table stays readable on a narrow terminal
        foreach ($long as $key => $plan) {
            $output->writeln('<info>'.$key.'</info>:');

            foreach ($plan as $change) {
                $output->writeln('  - '.$change);
            }
        }

        foreach ($settings as $key => $setting) {
            foreach ($setting->warnings() as $warning) {
                $output->writeln('<comment>'.$key.': '.$warning.'</comment>');
            }
        }
    }
}
