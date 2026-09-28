<?php

namespace Gogol\VpsManagerCLI\Command\Backup;

use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class BackupSetupCommand extends Command
{
    /**
     * The console output.
     *
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    private $output;

    /**
     * Linux user which owns the backups directory.
     *
     * @var string
     */
    private $default_backup_user = 'vpsmanager_backups';

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('backup:setup')->setDescription('Setup backup configuration');
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

        $this->output = $output;

        $output->writeln('');

        $this->setConfig($input, $output, $this->getHelper('question'));

        $output->writeln('<info>Backup setup has been successfully completed.</info>');

        return Command::SUCCESS;
    }

    /**
     * Ask for all backup configuration values and save them into config file.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @return void
     *
     * @throws \Exception
     */
    public function setConfig($input, $output, $helper): void
    {
        $vm = vpsManager();
        $config = $vm->config();

        $config_data = [
            'setBackupServerName' => [
                'config_key' => ($k = 'backup_server_name'),
                'default' => $vm->config($k, 'MyVpsServer'),
            ],
            'setBackupPath' => [
                'config_key' => ($k = 'backup_path'),
                'default' => $vm->config($k, '/var/vpsmanager_backups'),
            ],
            'setWWWPath' => [
                'config_key' => ($k = 'backup_www_path'),
                'default' => $vm->config($k, '/var/www'),
            ],
            'setMaxWWWBackups' => [
                'config_key' => ($k = 'backup_www_max_limit'),
                'default' => $vm->config($k, 2),
            ],
            'setBackupDirectories' => [
                'config_key' => ($k = 'backup_directories'),
                'default' => $vm->config($k, implode(';', ['/etc/nginx', '/etc/php', '/etc/mysql --exclude="*/debian.cnf"', '/etc/ssh/sshd_config', '/var/spool/cron/crontabs'])),
            ],
            'setEmailNotifications' => [
                'config_key' => ($k = 'email_notifications'),
                'default' => $vm->config($k, true),
            ],
            'setEmailReceiver' => [
                'config_key' => ($k = 'email_receiver'),
                'default' => $vm->config($k, null),
            ],
            'setEmailServer' => [
                'config_key' => ($k = 'email_server'),
                'default' => $vm->config($k, null),
            ],
            'setEmailUsername' => [
                'config_key' => ($k = 'email_username'),
                'default' => $vm->config($k, null),
            ],
            'setEmailPassword' => [
                'config_key' => ($k = 'email_password'),
                'default' => $vm->config($k, null),
            ],
            'setRemoteBackups' => [
                'config_key' => ($k = 'remote_backups'),
                'default' => $vm->config($k, true),
            ],
            'setRemoteServer' => [
                'config_key' => ($k = 'remote_server'),
                'default' => $vm->config($k, null),
            ],
            'setRemoteUser' => [
                'config_key' => ($k = 'remote_user'),
                'default' => $vm->config($k, $this->default_backup_user),
            ],
            'setRemoteBackupPath' => [
                'config_key' => ($k = 'remote_path'),
                'default' => $vm->config($k),
            ],
            'setRemoteBackupLimit' => [
                'config_key' => ($k = 'remote_backup_limit'),
                'default' => $vm->config($k, 2),
            ],
            'addIntoCrontab' => [
                'config_key' => ($k = 'crontab_add'),
                'default' => $vm->config($k, true),
            ],
        ];

        // Ask for each config property, skipped questions return false
        foreach ($config_data as $method => $data) {
            $config[$data['config_key']] ??= null;

            $answered = $this->{$method}($input, $output, $helper, $config[$data['config_key']], $data['default'], $config);

            if ($answered !== false) {
                $output->writeln('');
            }
        }

        if (! vpsManager()->saveConfig($config)) {
            throw new Exception('Setup failed. Config could not be saved into '.vpsManagerPath().'/config.php');
        }

        // Force reloading of config
        vpsManager()->bootConfig(true);
    }

    /**
     * Get confirmation hint by default value.
     *
     * @param  mixed  $default
     * @return string
     */
    private function getConfirmationHint($default): string
    {
        return $default ? '(Y/n)' : '(y/N)';
    }

    /**
     * Ask if backups should be synced to remote server.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @return void
     */
    public function setRemoteBackups($input, $output, $helper, &$config, $default): void
    {
        $question = new ConfirmationQuestion('<info>Would you like to backup your data remotely? '.$this->getConfirmationHint($default).'</info> ', (bool) $default);

        $value = $config = $helper->ask($input, $output, $question);

        $output->writeln('Remote backups: <comment>'.($value ? 'ON' : 'OFF').'</comment>');
    }

    /**
     * Ask if error email notifications should be enabled.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @return void
     */
    public function setEmailNotifications($input, $output, $helper, &$config, $default): void
    {
        $question = new ConfirmationQuestion('<info>Would you like to enable error email notifications? '.$this->getConfirmationHint($default).'</info> ', (bool) $default);

        $value = $config = $helper->ask($input, $output, $question);

        $output->writeln('Email notifications: <comment>'.($value ? 'ON' : 'OFF').'</comment>');
    }

    /**
     * Ask for server name.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @return void
     */
    private function setBackupServerName($input, $output, $helper, &$config, $default): void
    {
        $output->writeln('<info>Please set name of your server:</info>');

        $question = new Question('Set name of your local server or press enter to use default name <comment>'.$default.'</comment>: ', null);

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used name: <comment>'.$value.'</comment>');
    }

    /**
     * Ask for SMTP server address.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @param  array  $total_config
     * @return false|void
     */
    private function setEmailServer($input, $output, $helper, &$config, $default, $total_config)
    {
        if (! $total_config['email_notifications']) {
            return false;
        }

        $output->writeln('<info>Please set SMTP server address:</info>');

        $question = new Question(
            'Type SMTP server address in format <comment>(smtp.example.com:465)</comment>'.($default ? ' or press enter to use default address <comment>'.$default.'</comment>' : '').': ',
            null,
        );
        $question->setValidator($this->requiredValidator($default, 'Please fill SMTP address of mail server.'));

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used SMTP server: <comment>'.$value.'</comment>');
    }

    /**
     * Ask for SMTP username.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @param  array  $total_config
     * @return false|void
     */
    private function setEmailUsername($input, $output, $helper, &$config, $default, $total_config)
    {
        if (! $total_config['email_notifications']) {
            return false;
        }

        $output->writeln('<info>Please set SMTP username:</info>');

        $question = new Question(
            'Type SMTP username in format <comment>(vpsmanager@example.com)</comment>'.($default ? ' or press enter to use default username <comment>'.$default.'</comment>' : '').': ',
            null,
        );
        $question->setValidator($this->requiredValidator($default, 'Please fill SMTP username of mail server.'));

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used SMTP username: <comment>'.$value.'</comment>');
    }

    /**
     * Ask for notifications receiver email address.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @param  array  $total_config
     * @return false|void
     */
    private function setEmailReceiver($input, $output, $helper, &$config, $default, $total_config)
    {
        if (! $total_config['email_notifications']) {
            return false;
        }

        $output->writeln('<info>Please set your email address:</info>');

        $question = new Question(
            'Type your email address where you want to receive email notifications'.($default ? ' or press enter to use default email <comment>'.$default.'</comment>' : '').': ',
            null,
        );
        $question->setValidator($this->requiredValidator($default, 'Please fill email address.'));

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used email address: <comment>'.$value.'</comment>');
    }

    /**
     * Ask for SMTP password.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @param  array  $total_config
     * @return false|void
     */
    private function setEmailPassword($input, $output, $helper, &$config, $default, $total_config)
    {
        if (! $total_config['email_notifications']) {
            return false;
        }

        $output->writeln('<info>Please set SMTP password:</info>');

        $question = new Question('Type SMTP password'.($default ? ' or press enter to keep current password' : '').': ', null);
        $question->setHidden(true);
        $question->setHiddenFallback(true);
        $question->setValidator($this->requiredValidator($default, 'Please fill SMTP password of your account.'));

        $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used SMTP password: <comment>********</comment>');
    }

    /**
     * Ask for WWW path of websites which should be backed up.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @return void
     */
    private function setWWWPath($input, $output, $helper, &$config, $default): void
    {
        $output->writeln('<info>Please set WWW path of your websites which you want to backup.</info>');

        $question = new Question('Type new path or press enter to use default <comment>'.$default.'</comment> path: ', null);
        $question->setValidator(function ($path) {
            if (! $path) {
                return $path;
            }

            if (! file_exists($path)) {
                throw new Exception('Please fill valid existing path.');
            }

            return trim_end($path, '/');
        });

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used path: <comment>'.$value.'</comment>');
    }

    /**
     * Ask for remote server address.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @param  array  $total_config
     * @return false|void
     */
    private function setRemoteServer($input, $output, $helper, &$config, $default, $total_config)
    {
        if (! $total_config['remote_backups']) {
            return false;
        }

        $output->writeln('<info>Please set remote server address</info>');

        $question = new Question(
            'Type IP address or domain name of your server where backup data will be stored'.($default ? ' or press enter to use default address <comment>'.$default.'</comment>' : '').': ',
            null,
        );
        $question->setValidator($this->requiredValidator($default, 'Please fill valid address of server.'));

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used IP/Domain: <comment>'.$value.'</comment>');
    }

    /**
     * Ask how many local WWW and directory backups should be kept.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @return void
     */
    public function setMaxWWWBackups($input, $output, $helper, &$config, $default): void
    {
        $question = new Question('<info>How many backups would you like to keep? (1-3)</info>'."\n".'Or press enter to use default <comment>'.$default.'</comment> backups: ', null);
        $question->setValidator($this->numericValidator());

        $value = $config = (int) ($helper->ask($input, $output, $question) ?: $default);

        $output->writeln('Max WWW backups: <comment>'.$value.'</comment>');
    }

    /**
     * Ask how many latest backups should be stored on remote server.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @param  array  $total_config
     * @return false|void
     */
    private function setRemoteBackupLimit($input, $output, $helper, &$config, $default, $total_config)
    {
        if (! $total_config['remote_backups']) {
            return false;
        }

        $output->writeln('<info>Please set remote backups limit</info>');

        $question = new Question(
            'Type how many latest backups from local machine should be stored on remote server.'."\n".
            'All older backups on remote server will be deleted.'."\n".
            'Or press enter to use default <comment>'.$default.'</comment> backups: ',
            null,
        );
        $question->setValidator($this->numericValidator());

        $value = $config = (int) ($helper->ask($input, $output, $question) ?: $default);

        $output->writeln('Latest remote backups: <comment>'.$value.'</comment>');
    }

    /**
     * Ask for remote server user and generate SSH keys.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @param  array  $total_config
     * @return false|void
     */
    private function setRemoteUser($input, $output, $helper, &$config, $default, $total_config)
    {
        if (! $total_config['remote_backups']) {
            return false;
        }

        $output->writeln('<info>Please set user of remote server</info>');

        $question = new Question('Type linux user of remote backup server or press enter to use default <comment>'.$default.'</comment>: ', null);

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used user: <comment>'.$value.'</comment>');

        $this->generateRSA($total_config);
    }

    /**
     * Generate SSH keys for remote backups if they do not exist.
     *
     * @param  array  $total_config
     * @return void
     */
    private function generateRSA($total_config): void
    {
        $rsa_dir = $total_config['backup_path'].'/.ssh';
        $rsa_path = $rsa_dir.'/id_rsa';

        // Generate RSA keys only if they do not exist yet
        if (! file_exists($rsa_path)) {
            $this->output->writeln('<info>Generating RSA keys into:</info> '.$rsa_dir);
            exec('mkdir -p '.$rsa_dir);
            exec('ssh-keygen -t rsa -q -N "" -f '.$rsa_path);
            file_put_contents($rsa_dir.'/authorized_keys', '# Paste here your public key from remote server');
        }

        $this->output->writeln(
            'Add this public key to your remote server into <info>'.$rsa_dir.'/authorized_keys</info> file: '."\n".'<comment>'.trim(file_get_contents($rsa_path.'.pub')).'</comment>',
        );
    }

    /**
     * Ask for backup path on remote server.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @param  array  $total_config
     * @return false|void
     */
    private function setRemoteBackupPath($input, $output, $helper, &$config, $default, $total_config)
    {
        if (! $total_config['remote_backups']) {
            return false;
        }

        $default = $default ?: '/var/vpsmanager_backups/remote/'.$total_config['backup_server_name'];

        $output->writeln('<info>Please set backup path on remote server where all backups of your local resources will be stored.</info>');

        $question = new Question('Type new path or press enter to use default remote <comment>'.$default.'</comment> destination path: ', null);

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used path: <comment>'.$value.'</comment>');
    }

    /**
     * Ask for local backup path, create backup user and set directory permissions.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @return void
     *
     * @throws \Exception
     */
    private function setBackupPath($input, $output, $helper, &$config, $default): void
    {
        $output->writeln('<info>Please set backup path where all local backups of your resources will be stored.</info>');

        $question = new Question('Type new path or press enter to use default <comment>'.$default.'</comment> path: ', null);

        $value = $config = trim_end($helper->ask($input, $output, $question) ?: $default, '/');

        $this->createUserForBackupDirectory($value);
        $this->setBackupDirectoryPermissions($value);
    }

    /**
     * Create user which owns backup directory.
     *
     * @param  string  $path
     * @return void
     *
     * @throws \Exception
     */
    private function createUserForBackupDirectory($path): void
    {
        if (vpsManager()->server()->existsUser($this->default_backup_user)) {
            return;
        }

        exec('useradd -s /bin/bash -d '.$path.' -U '.$this->default_backup_user, $output, $return_var);

        if ($return_var != 0) {
            throw new Exception('Directory user '.$this->default_backup_user.' could not be created.');
        }

        $this->output->writeln('User created: <comment>'.$this->default_backup_user.'</comment>');
    }

    /**
     * Create backup directory and set its permissions.
     *
     * @param  string  $path
     * @return void
     */
    private function setBackupDirectoryPermissions($path): void
    {
        exec('mkdir -p '.$path);
        exec('chmod 700 -R '.$path);
        exec('chown '.$this->default_backup_user.':'.$this->default_backup_user.' -R '.$path);

        $this->output->writeln('Directory used and permissions changed: <comment>'.$path.'</comment>');
    }

    /**
     * Ask for additional directories which should be backed up.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @return void
     */
    private function setBackupDirectories($input, $output, $helper, &$config, $default): void
    {
        $output->writeln('<info>Please set which additional directories should be backed up.</info>');

        $question = new Question(
            'Type multiple paths separated with <comment>;</comment> in format <comment>path1;path2;path3</comment>.'."\n".
            'If you don\'t want to backup any directories, type <comment>-</comment> and hit enter.'."\n".
            'Or press enter to use default <comment>'.$default.'</comment> paths: ',
            null,
        );

        $value = $config = trim_end($helper->ask($input, $output, $question) ?: $default, '/');

        $output->writeln('Used paths: <comment>'.$value.'</comment>');
    }

    /**
     * Ask if backups should be added into root crontab.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @return void
     */
    private function addIntoCrontab($input, $output, $helper, &$config, $default): void
    {
        $question = new ConfirmationQuestion('<info>Would you like to run backups daily at</info> <comment>4AM</comment><info>? Crontab entry will be added. '.$this->getConfirmationHint($default).'</info> ', (bool) $default);

        $value = $config = $helper->ask($input, $output, $question);

        $output->writeln('Crontab: <comment>'.($value ? 'ON' : 'OFF').'</comment>');

        $app_path = implode('/', array_slice(explode('/', __DIR__), 0, -3));
        $line = '0 4 * * * php '.$app_path.'/vpsmanager backup:run';

        if (! $value) {
            $output->writeln('You can add crontab manually later: <info>'.$line.'</info>');

            return;
        }

        $crontab_path = '/var/spool/cron/crontabs/root';
        $crontab_data = @file_get_contents($crontab_path) ?: '';

        if (str_contains($crontab_data, 'vpsmanager backup:run')) {
            $output->writeln('Crontab for backups already exists.');

            return;
        }

        @file_put_contents($crontab_path, $line."\n", FILE_APPEND);

        $output->writeln('Crontab has been added at: <comment>4AM</comment>');
    }

    /**
     * Get validator which requires a value when no default value exists.
     *
     * @param  mixed  $default
     * @param  string  $message
     * @return \Closure
     */
    private function requiredValidator($default, string $message): \Closure
    {
        return function ($value) use ($default, $message) {
            if (! $default && empty($value)) {
                throw new Exception($message);
            }

            return $value;
        };
    }

    /**
     * Get validator which allows only empty or positive numeric values.
     *
     * @return \Closure
     */
    private function numericValidator(): \Closure
    {
        return function ($value) {
            if ($value !== null && $value !== '' && (! is_numeric($value) || (int) $value < 1)) {
                throw new Exception('Please fill valid number of backups.');
            }

            return $value;
        };
    }
}
