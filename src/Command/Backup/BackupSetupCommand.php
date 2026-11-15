<?php

namespace Gogol\VpsManagerCLI\Command\Backup;

use Exception;
use Gogol\VpsManagerCLI\Helpers\Backup;
use Gogol\VpsManagerCLI\Helpers\Cron;
use Gogol\VpsManagerCLI\Helpers\Restic;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class BackupSetupCommand extends Command
{
    /**
     * Keys of the old tar.gz backups and the remote rsync, removed by the setup.
     *
     * @var array
     */
    const OBSOLETE_KEYS = ['backup_path', 'backup_www_max_limit', 'remote_backups', 'remote_server', 'remote_user', 'remote_path', 'remote_backup_limit'];

    /**
     * Default directory of a local repository.
     *
     * @var string
     */
    const DEFAULT_LOCAL_PATH = '/var/vpsmanager_backups/restic';

    /**
     * The console output.
     *
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    private $output;

    /**
     * The console input.
     *
     * @var \Symfony\Component\Console\Input\InputInterface
     */
    private $input;

    /**
     * The console question helper.
     *
     * @var \Symfony\Component\Console\Helper\QuestionHelper
     */
    private $helper;

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('backup:setup')->setDescription('Setup backups into a restic repository, local or on an SFTP server');
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
        $this->input = $input;
        $this->helper = $this->getHelper('question');

        // The passwords of the repository can be typed only interactively
        if (! $input->isInteractive()) {
            throw new Exception('backup:setup must run interactively.');
        }

        $output->writeln('');

        $config = $this->askConfig(vpsManager()->config());

        $this->save($config);

        $this->installRestic();

        $config = $this->prepareRepository($config);

        $this->save($config);

        $this->addRecoveryPassword($config);

        $config = $this->removeOldBackups($config);

        $this->save($config);

        vpsManager()->backup()->installLogrotate();

        foreach (vpsManager()->cron()->write() as $change) {
            $output->writeln('<comment>'.$change.'</comment>');
        }

        $output->writeln('');
        $output->writeln('<info>Backup setup has been successfully completed.</info>');
        $output->writeln('Test it with <comment>php vpsmanager backup:run</comment> and <comment>php vpsmanager backup:list</comment>.');

        return Command::SUCCESS;
    }

    /**
     * Ask for all backup configuration values.
     *
     * @param  array  $config
     * @return array
     */
    private function askConfig(array $config): array
    {
        $steps = [
            'askServerName',
            'askRepositoryType',
            'askLocalPath',
            'askSftpHost',
            'askSftpUser',
            'askSftpPort',
            'askSftpPath',
            'askSftpKey',
            'askWWWPath',
            'askBackupDirectories',
            'askEmailNotifications',
            'askEmailReceiver',
            'askEmailServer',
            'askEmailUsername',
            'askEmailPassword',
            'askCrontab',
        ];

        foreach ($steps as $step) {
            if ($this->{$step}($config) !== false) {
                $this->output->writeln('');
            }
        }

        $config['backup_keep_databases'] ??= Backup::KEEP_DATABASES;
        $config['backup_keep_data'] ??= Backup::KEEP_DATA;

        return $config;
    }

    /**
     * Save the configuration and reload it.
     *
     * @param  array  $config
     * @return void
     *
     * @throws \Exception
     */
    private function save(array $config): void
    {
        if (! vpsManager()->saveConfig($config)) {
            throw new Exception('Setup failed. Config could not be saved into '.vpsManagerPath().'/config.php');
        }

        vpsManager()->bootConfig(true);
    }

    /**
     * Ask a question.
     *
     * @param  \Symfony\Component\Console\Question\Question  $question
     * @return mixed
     */
    private function ask(Question $question)
    {
        return $this->helper->ask($this->input, $this->output, $question);
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
     * Ask for the name of the server, the host of its snapshots.
     *
     * @param  array  $config
     * @return void
     */
    private function askServerName(array &$config): void
    {
        $default = $config['backup_server_name'] ?? 'MyVpsServer';

        $this->output->writeln('<info>Please set name of your server:</info>');

        $value = $this->ask(new Question('Set name of your server or press enter to use <comment>'.$default.'</comment>: ')) ?: $default;

        $config['backup_server_name'] = $value;

        $this->output->writeln('Used name: <comment>'.$value.'</comment>');
    }

    /**
     * Ask where the repository is stored.
     *
     * @param  array  $config
     * @return void
     */
    private function askRepositoryType(array &$config): void
    {
        $choices = ['sftp' => 'SFTP server (e.g. Hetzner Storage Box)', 'local' => 'Local directory (or a mounted disk)'];
        $default = $config['backup_repository_type'] ?? 'sftp';

        $question = new ChoiceQuestion('<info>Where should the backups be stored?</info>', $choices, $default);

        $config['backup_repository_type'] = $value = $this->ask($question);

        $this->output->writeln('Repository: <comment>'.$choices[$value].'</comment>');
    }

    /**
     * Ask for the directory of a local repository.
     *
     * @param  array  $config
     * @return false|void
     */
    private function askLocalPath(array &$config)
    {
        if ($config['backup_repository_type'] !== 'local') {
            return false;
        }

        $default = $config['backup_local_path'] ?? self::DEFAULT_LOCAL_PATH;

        $this->output->writeln('<info>Please set the directory of the repository.</info>');

        $value = rtrim($this->ask(new Question('Type new path or press enter to use <comment>'.$default.'</comment>: ')) ?: $default, '/');

        exec('mkdir -p '.escapeshellarg($value).' && chmod 700 '.escapeshellarg($value));

        $config['backup_local_path'] = $value;

        $this->output->writeln('Used path: <comment>'.$value.'</comment>');
    }

    /**
     * Ask for the address of the SFTP server.
     *
     * @param  array  $config
     * @return false|void
     */
    private function askSftpHost(array &$config)
    {
        if ($config['backup_repository_type'] !== 'sftp') {
            return false;
        }

        $default = $config['backup_sftp_host'] ?? null;

        $this->output->writeln('<info>Please set the address of the SFTP server.</info>');

        $question = new Question('Type the address, e.g. <comment>u123456-sub1.your-storagebox.de</comment>'.($default ? ', or press enter to use <comment>'.$default.'</comment>' : '').': ');
        $question->setValidator($this->requiredValidator($default, 'Please fill the address of the SFTP server.'));

        $config['backup_sftp_host'] = $value = $this->ask($question) ?: $default;

        $this->output->writeln('Used server: <comment>'.$value.'</comment>');
    }

    /**
     * Ask for the user of the SFTP server.
     *
     * @param  array  $config
     * @return false|void
     */
    private function askSftpUser(array &$config)
    {
        if ($config['backup_repository_type'] !== 'sftp') {
            return false;
        }

        // A Storage Box sub-account has the name of its host
        $default = $config['backup_sftp_user'] ?? (str_ends_with($config['backup_sftp_host'], '.your-storagebox.de') ? explode('.', $config['backup_sftp_host'])[0] : null);

        $this->output->writeln('<info>Please set the user of the SFTP server.</info>');

        $question = new Question('Type the user'.($default ? ' or press enter to use <comment>'.$default.'</comment>' : '').': ');
        $question->setValidator($this->requiredValidator($default, 'Please fill the user of the SFTP server.'));

        $config['backup_sftp_user'] = $value = $this->ask($question) ?: $default;

        $this->output->writeln('Used user: <comment>'.$value.'</comment>');
    }

    /**
     * Ask for the SSH port of the SFTP server.
     *
     * @param  array  $config
     * @return false|void
     */
    private function askSftpPort(array &$config)
    {
        if ($config['backup_repository_type'] !== 'sftp') {
            return false;
        }

        // A Storage Box accepts keys of the authorized_keys file on port 23
        $default = $config['backup_sftp_port'] ?? (str_ends_with($config['backup_sftp_host'], '.your-storagebox.de') ? 23 : 22);

        $question = new Question('<info>Type the SSH port</info> or press enter to use <comment>'.$default.'</comment>: ');
        $question->setValidator(function ($value) {
            if ($value !== null && $value !== '' && (! ctype_digit((string) $value) || (int) $value < 1 || (int) $value > 65535)) {
                throw new Exception('Please fill a valid port.');
            }

            return $value;
        });

        $config['backup_sftp_port'] = $value = (int) ($this->ask($question) ?: $default);

        $this->output->writeln('Used port: <comment>'.$value.'</comment>');
    }

    /**
     * Ask for the directory of the repository on the SFTP server.
     *
     * @param  array  $config
     * @return false|void
     */
    private function askSftpPath(array &$config)
    {
        if ($config['backup_repository_type'] !== 'sftp') {
            return false;
        }

        $default = $config['backup_sftp_path'] ?? 'restic';

        $question = new Question('<info>Type the directory of the repository</info>, relative to the home of the user, or press enter to use <comment>'.$default.'</comment>: ');

        $config['backup_sftp_path'] = $value = trim($this->ask($question) ?: $default);

        $this->output->writeln('Used directory: <comment>'.$value.'</comment>');
    }

    /**
     * Ask for the SSH key of the SFTP server, generate it when it does not exist and wait until it is authorized.
     *
     * @param  array  $config
     * @return false|void
     */
    private function askSftpKey(array &$config)
    {
        if ($config['backup_repository_type'] !== 'sftp') {
            return false;
        }

        $default = $config['backup_sftp_key'] ?? Restic::KEY_PATH;

        $question = new Question('<info>Type the path of the SSH key</info> or press enter to use <comment>'.$default.'</comment>: ');

        $config['backup_sftp_key'] = $path = $this->ask($question) ?: $default;

        if (! file_exists($path)) {
            exec('mkdir -p '.escapeshellarg(dirname($path)).' && chmod 700 '.escapeshellarg(dirname($path)));
            exec('ssh-keygen -t ed25519 -q -N "" -C '.escapeshellarg('vpsmanager-backup-'.$config['backup_server_name']).' -f '.escapeshellarg($path), $output, $code);

            if ($code !== 0) {
                throw new Exception('SSH key '.$path.' could not be generated.');
            }

            $this->output->writeln('Generated SSH key: <comment>'.$path.'</comment>');
        }

        $this->output->writeln('Add this public key into <comment>.ssh/authorized_keys</comment> of <comment>'.$config['backup_sftp_user'].'</comment> on the SFTP server:');
        $this->output->writeln('<info>'.trim((string) @file_get_contents($path.'.pub')).'</info>');

        $this->ask(new Question('Press enter when the key is added.'));
    }

    /**
     * Ask for WWW path of websites which should be backed up.
     *
     * @param  array  $config
     * @return void
     */
    private function askWWWPath(array &$config): void
    {
        $default = $config['backup_www_path'] ?? '/var/www';

        $this->output->writeln('<info>Please set WWW path of your websites which you want to backup.</info>');

        $question = new Question('Type new path or press enter to use default <comment>'.$default.'</comment> path: ');
        $question->setValidator(function ($path) {
            if ($path && ! file_exists($path)) {
                throw new Exception('Please fill valid existing path.');
            }

            return $path ? rtrim($path, '/') : $path;
        });

        $config['backup_www_path'] = $value = $this->ask($question) ?: $default;

        $this->output->writeln('Used path: <comment>'.$value.'</comment>');
    }

    /**
     * Ask for additional directories which should be backed up.
     *
     * @param  array  $config
     * @return void
     */
    private function askBackupDirectories(array &$config): void
    {
        $default = $config['backup_directories'] ?? implode(';', ['/etc/nginx', '/etc/php', '/etc/mysql --exclude="*/debian.cnf"', '/etc/ssh/sshd_config', '/var/spool/cron/crontabs']);

        $this->output->writeln('<info>Please set which additional directories should be backed up.</info>');

        $question = new Question(
            'Type multiple paths separated with <comment>;</comment> in format <comment>path1;path2;path3</comment>.'."\n".
            'If you don\'t want to backup any directories, type <comment>-</comment> and hit enter.'."\n".
            'Or press enter to use default <comment>'.$default.'</comment> paths: ',
        );

        $config['backup_directories'] = $value = trim_end($this->ask($question) ?: $default, '/');

        $this->output->writeln('Used paths: <comment>'.$value.'</comment>');
    }

    /**
     * Ask if error email notifications should be enabled.
     *
     * @param  array  $config
     * @return void
     */
    private function askEmailNotifications(array &$config): void
    {
        $default = $config['email_notifications'] ?? true;

        $question = new ConfirmationQuestion('<info>Would you like to enable error email notifications? '.$this->getConfirmationHint($default).'</info> ', (bool) $default);

        $config['email_notifications'] = $value = $this->ask($question);

        $this->output->writeln('Email notifications: <comment>'.($value ? 'ON' : 'OFF').'</comment>');
    }

    /**
     * Ask for notifications receiver email address.
     *
     * @param  array  $config
     * @return false|void
     */
    private function askEmailReceiver(array &$config)
    {
        return $this->askEmailValue($config, 'email_receiver', 'Please set your email address:', 'Type your email address where you want to receive email notifications', 'Please fill email address.');
    }

    /**
     * Ask for SMTP server address.
     *
     * @param  array  $config
     * @return false|void
     */
    private function askEmailServer(array &$config)
    {
        return $this->askEmailValue($config, 'email_server', 'Please set SMTP server address:', 'Type SMTP server address in format <comment>(smtp.example.com:465)</comment>', 'Please fill SMTP address of mail server.');
    }

    /**
     * Ask for SMTP username.
     *
     * @param  array  $config
     * @return false|void
     */
    private function askEmailUsername(array &$config)
    {
        return $this->askEmailValue($config, 'email_username', 'Please set SMTP username:', 'Type SMTP username in format <comment>(vpsmanager@example.com)</comment>', 'Please fill SMTP username of mail server.');
    }

    /**
     * Ask for a value of the email notifications.
     *
     * @param  array  $config
     * @param  string  $key
     * @param  string  $title
     * @param  string  $text
     * @param  string  $required
     * @return false|void
     */
    private function askEmailValue(array &$config, string $key, string $title, string $text, string $required)
    {
        if (! $config['email_notifications']) {
            return false;
        }

        $default = $config[$key] ?? null;

        $this->output->writeln('<info>'.$title.'</info>');

        $question = new Question($text.($default ? ' or press enter to use <comment>'.$default.'</comment>' : '').': ');
        $question->setValidator($this->requiredValidator($default, $required));

        $config[$key] = $value = $this->ask($question) ?: $default;

        $this->output->writeln('Used: <comment>'.$value.'</comment>');
    }

    /**
     * Ask for SMTP password.
     *
     * @param  array  $config
     * @return false|void
     */
    private function askEmailPassword(array &$config)
    {
        if (! $config['email_notifications']) {
            return false;
        }

        $default = $config['email_password'] ?? null;

        $this->output->writeln('<info>Please set SMTP password:</info>');

        $question = new Question('Type SMTP password'.($default ? ' or press enter to keep current password' : '').': ');
        $question->setHidden(true);
        $question->setHiddenFallback(true);
        $question->setValidator($this->requiredValidator($default, 'Please fill SMTP password of your account.'));

        $config['email_password'] = $this->ask($question) ?: $default;

        $this->output->writeln('Used SMTP password: <comment>********</comment>');
    }

    /**
     * Ask if the backups run from cron and how often the databases are backed up.
     *
     * @param  array  $config
     * @return void
     */
    private function askCrontab(array &$config): void
    {
        $default = $config['crontab_add'] ?? true;

        $question = new ConfirmationQuestion('<info>Would you like to run backups daily at</info> <comment>'.Cron::BACKUP_HOUR.'AM</comment> <info>and their maintenance on Sundays from cron? '.$this->getConfirmationHint($default).'</info> ', (bool) $default);

        $config['crontab_add'] = $value = $this->ask($question);

        $this->output->writeln('Cron: <comment>'.($value ? 'ON' : 'OFF').'</comment>');

        if (! $value) {
            return;
        }

        // Servers on one storage start their backups at their own minute
        $config['backup_cron_minute'] ??= Cron::minuteOf((string) $config['backup_server_name']);

        $this->output->writeln('Minute of the backups: <comment>'.$config['backup_cron_minute'].'</comment> (backup_cron_minute)');

        $default = $config['backup_databases_interval'] ?? Cron::DATABASES_INTERVAL;
        $intervals = implode(', ', array_filter(Cron::DATABASES_INTERVALS));

        $this->output->writeln('');
        $this->output->writeln('<info>How often should the databases be backed up?</info>');

        $question = new Question('Type every how many hours (<comment>'.$intervals.'</comment>), <comment>0</comment> for only the daily backup, or press enter to use <comment>'.$default.'</comment>: ');
        $question->setValidator(function ($value) {
            if ($value !== null && $value !== '' && (! ctype_digit((string) $value) || ! in_array((int) $value, Cron::DATABASES_INTERVALS))) {
                throw new Exception('Please fill one of '.implode(', ', Cron::DATABASES_INTERVALS).'.');
            }

            return $value;
        });

        $value = $this->ask($question);
        $config['backup_databases_interval'] = $value = ($value === null || $value === '') ? (int) $default : (int) $value;

        $this->output->writeln('Databases: <comment>'.($value ? 'every '.$value.' hours' : 'only daily').'</comment>');
    }

    /**
     * Install the pinned restic release when restic is missing or too old.
     *
     * @return void
     *
     * @throws \Exception
     */
    private function installRestic(): void
    {
        $version = vpsManager()->restic()->installedVersion();

        if (Restic::isSupportedVersion($version)) {
            $this->output->writeln('Installed restic: <comment>'.$version.'</comment>');

            return;
        }

        $this->output->writeln('<info>Installing restic '.Restic::VERSION.' into '.Restic::BINARY.'...</info>');

        if (($result = vpsManager()->restic()->install()) !== true) {
            throw new Exception($result);
        }

        $this->output->writeln('Installed restic: <comment>'.vpsManager()->restic()->installedVersion().'</comment>');
    }

    /**
     * Connect to the repository with the password of the server, create the repository when it does not exist, or add
     * the password of the server into an existing repository opened by another of its passwords.
     *
     * @param  array  $config
     * @return array
     *
     * @throws \Exception
     */
    private function prepareRepository(array $config): array
    {
        $config['backup_password'] = ($config['backup_password'] ?? null) ?: getRandomPassword(40);

        $restic = vpsManager()->restic()->using($config);
        $repository = Restic::repository($config);

        $this->output->writeln('');
        $this->output->writeln('<info>Connecting to the repository</info> <comment>'.$repository.'</comment>');

        $code = $restic->run(['cat', 'config'], $output, $error);

        if ($code === 0) {
            $this->output->writeln('The repository exists and the password of the server opens it.');

            return $config;
        }

        if ($code === Restic::EXIT_NO_REPOSITORY) {
            if ($restic->run(['init'], $output, $error) !== 0) {
                throw new Exception('The repository could not be created: '.Restic::errorMessage($error));
            }

            $this->output->writeln('<info>The repository has been created.</info>');

            return $config;
        }

        if ($code !== Restic::EXIT_WRONG_PASSWORD) {
            throw new Exception('The repository is not available: '.Restic::errorMessage($error));
        }

        // The repository exists with other passwords, e.g. of a reinstalled server
        $this->output->writeln('<comment>The repository exists, but the password of the server does not open it.</comment>');

        while (true) {
            $existing = $this->askHidden('Type a password which opens the repository (e.g. the recovery password): ');

            if ($restic->run(['cat', 'config'], $output, $error, $existing) === 0) {
                break;
            }

            $this->output->writeln('<error>The password does not open the repository.</error>');
        }

        $this->addKey($restic, $config['backup_password'], 'vpsmanager', $existing);

        $this->output->writeln('<info>The password of the server has been added into the repository.</info>');

        return $config;
    }

    /**
     * Ask for the recovery password and add it into the repository, unless it opens the repository already.
     *
     * @param  array  $config
     * @return void
     *
     * @throws \Exception
     */
    private function addRecoveryPassword(array $config): void
    {
        $restic = vpsManager()->restic()->using($config);

        $this->output->writeln('');
        $this->output->writeln('<info>Recovery password</info>');
        $this->output->writeln('The recovery password opens the repository without this server. It is not saved on the server, keep it in your password manager.');

        while (true) {
            $password = $this->askHidden('Type the recovery password: ');

            if (mb_strlen($password) < 12) {
                $this->output->writeln('<error>The recovery password must have at least 12 characters.</error>');

                continue;
            }

            if ($this->askHidden('Type the recovery password again: ') !== $password) {
                $this->output->writeln('<error>The passwords do not match.</error>');

                continue;
            }

            break;
        }

        if ($restic->run(['cat', 'config'], $output, $error, $password) === 0) {
            $this->output->writeln('The recovery password opens the repository already.');

            return;
        }

        $this->addKey($restic, $password, 'recovery', $config['backup_password']);

        $this->output->writeln('<info>The recovery password has been added into the repository.</info>');
    }

    /**
     * Add a password into the repository.
     *
     * @param  \Gogol\VpsManagerCLI\Helpers\Restic  $restic
     * @param  string  $password  new password
     * @param  string  $user  label of the password
     * @param  string  $opening  password which opens the repository
     * @return void
     *
     * @throws \Exception
     */
    private function addKey(Restic $restic, string $password, string $user, string $opening): void
    {
        $file = tempnam(sys_get_temp_dir(), 'vpsmanager-restic-');
        chmod($file, 0600);
        file_put_contents($file, $password);

        try {
            $code = $restic->run(['key', 'add', '--new-password-file', $file, '--user', $user, '--host', (string) vpsManager()->config('backup_server_name')], $output, $error, $opening);
        } finally {
            @unlink($file);
        }

        if ($code !== 0) {
            throw new Exception('The password could not be added into the repository: '.Restic::errorMessage($error));
        }
    }

    /**
     * Remove the old tar.gz backups and the obsolete keys of the configuration.
     *
     * @param  array  $config
     * @return array
     */
    private function removeOldBackups(array $config): array
    {
        $path = rtrim((string) ($config['backup_path'] ?? ''), '/');
        $directories = $path ? self::oldBackupDirectories($path) : [];
        $repository = rtrim((string) ($config['backup_local_path'] ?? ''), '/');

        // Never remove a local repository placed among the old backups
        $directories = array_values(array_filter($directories, fn ($directory) => ! $repository || ! str_starts_with($repository.'/', $directory.'/')));

        if ($directories) {
            $size = trim((string) shell_exec('du -sch '.implode(' ', array_map('escapeshellarg', $directories)).' 2>/dev/null | tail -1 | cut -f1'));

            $this->output->writeln('');
            $this->output->writeln('<info>Old tar.gz backups:</info> <comment>'.count($directories).'</comment> backups of <comment>'.$size.'</comment> in <comment>'.$path.'/local</comment>');

            if ($this->ask(new ConfirmationQuestion('<info>Delete the old tar.gz backups? (Y/n)</info> ', true))) {
                foreach ($directories as $directory) {
                    exec('rm -rf '.escapeshellarg($directory));
                }

                // Remove the emptied type directories
                foreach (['databases', 'dirs', 'www'] as $type) {
                    @rmdir($path.'/local/'.$type);
                }

                @rmdir($path.'/local');

                $this->output->writeln('Old backups have been deleted.');
            } else {
                $this->output->writeln('Old backups are kept in <comment>'.$path.'/local</comment>, delete them manually.');
            }
        }

        foreach (self::OBSOLETE_KEYS as $key) {
            unset($config[$key]);
        }

        return $config;
    }

    /**
     * Get the dated directories of the old tar.gz backups.
     *
     * @param  string  $path  backup_path of the old backups
     * @return array
     */
    public static function oldBackupDirectories(string $path): array
    {
        $directories = [];

        foreach (['databases', 'dirs', 'www'] as $type) {
            foreach (glob($path.'/local/'.$type.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', basename($directory))) {
                    $directories[] = $directory;
                }
            }
        }

        return $directories;
    }

    /**
     * Ask for a hidden value.
     *
     * @param  string  $text
     * @return string
     */
    private function askHidden(string $text): string
    {
        $question = new Question('<info>'.$text.'</info>');
        $question->setHidden(true);
        $question->setHiddenFallback(false);

        return (string) $this->ask($question);
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
}
