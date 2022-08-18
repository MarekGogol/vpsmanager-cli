<?php

namespace Gogol\VpsManagerCLI\Command\Backup;

use Gogol\VpsManagerCLI\Nginx\Nginx;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class BackupSetupCommand extends Command
{
    private $input;
    private $output;

    /**
     * Linux User for backups
     * @var string
     */
    private $default_backup_user = 'vpsmanager_backups';

    protected function configure()
    {
        $this->setName('backup:setup')
             ->setDescription('Setup backup configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        vpsManager()->bootConsole($output);

        $this->input = $input;
        $this->output = $output;
        $this->helper = $helper = $this->getHelper('question');

        $output->writeln('');

        $this->setConfig($input, $output, $helper);

        $output->writeln('<info>Backup setup has been successfully completed.</info>');

        return Command::SUCCESS;
    }

    public function setConfig($input, $output, $helper)
    {
        $vm = vpsManager();
        $config = $vm->config();

        $config_data = [
            'setBackupServerName' => [
                'config_key' => $k = 'backup_server_name',
                'default' => $vm->config($k, 'MyVpsServer')
            ],
            'setBackupPath' => [
                'config_key' => $k = 'backup_path',
                'default' => $vm->config($k, '/var/vpsmanager_backups')
            ],
            'setWWWPath' => [
                'config_key' => $k = 'backup_www_path',
                'default' => $vm->config($k, '/var/www')
            ],
            'setMaxWWWBackups' => [
                'config_key' => $k = 'backup_www_max_limit',
                'default' => $vm->config($k, 2),
            ],
            'setBackupDirectories' => [
                'config_key' => $k = 'backup_directories',
                'default' => $vm->config($k, implode(';', [
                    '/etc/nginx',
                    '/etc/php',
                    '/etc/mysql --exclude="*/debian.cnf"',
                    '/etc/ssh/sshd_config',
                    '/var/spool/cron/crontabs',
                ]))
            ],
            'setEmailNotifications' => [
                'config_key' => $k = 'email_notifications',
                'default' => $vm->config($k, true),
            ],
            'setEmailReceiver' => [
                'config_key' => $k = 'email_receiver',
                'default' => $vm->config($k, null),
            ],
            'setEmailServer' => [
                'config_key' => $k = 'email_server',
                'default' => $vm->config($k, null),
            ],
            'setEmailUsername' => [
                'config_key' => $k = 'email_username',
                'default' => $vm->config($k, null),
            ],
            'setEmailPassword' => [
                'config_key' => $k = 'email_password',
                'default' => $vm->config($k, null),
            ],
            'setRemoteBackups' => [
                'config_key' => $k = 'remote_backups',
                'default' => $vm->config($k, true),
            ],
            'setRemoteServer' => [
                'config_key' => $k = 'remote_server',
                'default' => $vm->config($k, null),
            ],
            'setRemoteUser' => [
                'config_key' => $k = 'remote_user',
                'default' => $vm->config($k, $this->default_backup_user),
            ],
            'setRemoteBackupPath' => [
                'config_key' => $k = 'remote_path',
                'default' => $vm->config($k),
            ],
            'setRemoteBackupLimit' => [
                'config_key' => $k = 'remote_backup_limit',
                'default' => $vm->config($k, 2),
            ],
            'addIntoCrontab' => [
                'config_key' => $k = 'crontab_add',
                'default' => $vm->config($k, true),
            ],
        ];

        //Set config properties
        foreach ($config_data as $method => $data)
        {
            $prev_config = $this->{$method}(
                $input,
                $output,
                $helper,
                $config[$data['config_key']],
                $data['default'],
                $config
            );

            if ( $prev_config !== false )
                $output->writeln('');
        }

        if ( ! vpsManager()->saveConfig($config) )
            throw new \Exception('Setup failed. Config could not be saved into '.vpsManagerPath().'/config.php');

        //Forced booting config
        vpsManager()->bootConfig(true);
    }

    public function setRemoteBackups($input, $output, $helper, &$config, $default)
    {
        $question = new ConfirmationQuestion('<info>Would you like to backup your data remotelly? (y/N)</info> ', $default);

        $value = $config = $helper->ask($input, $output, $question);

        $output->writeln('Remote backups: <comment>'.($value ? 'ON' : 'OFF').'</comment>');
    }

    public function setEmailNotifications($input, $output, $helper, &$config, $default)
    {
        $question = new ConfirmationQuestion('<info>Would you like to enable error email notifications? (y/N)</info> ', $default);

        $value = $config = $helper->ask($input, $output, $question);

        $output->writeln('Email notifications: <comment>'.($value ? 'ON' : 'OFF').'</comment>');
    }

    private function setBackupServerName($input, $output, $helper, &$config, $default, $total_config)
    {
        $output->writeln('<info>Please set name of your server:</info>');

        $question = new Question('Set name of your local server or press enter for using default name <comment>'.$default.'</comment>: ', null);

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used name: <comment>' . $value . '</comment>');
    }

    private function setEmailServer($input, $output, $helper, &$config, $default, $total_config)
    {
        if ( $total_config['email_notifications'] == false )
            return false;

        $output->writeln('<info>Please set SMTP server address:</info>');

        $question = new Question(
            'Type SMTP of your server in format <comment>(smtp.example.com:465)</comment>'.
            ($default ? ' or press enter for using default remote <comment>'.$default.'</comment>' : '').': '
        , null);
        $question->setValidator(function($value) use($default) {
            if ( !$default && empty($value) )
                throw new \Exception('Please fill SMTP address of mailserver.');

            return $value;
        });

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used SMTP Server: <comment>' . $value . '</comment>');
    }

    private function setEmailUsername($input, $output, $helper, &$config, $default, $total_config)
    {
        if ( $total_config['email_notifications'] == false )
            return false;

        $output->writeln('<info>Please set SMTP username:</info>');

        $question = new Question(
            'Type SMTP username in format <comment>(vpsmanager@example.com)</comment>'.
            ($default ? ' or press enter for using default username <comment>'.$default.'</comment>' : '').': '
        , null);
        $question->setValidator(function($value) use($default) {
            if ( ! $default && empty($value) )
                throw new \Exception('Please fill SMTP username of mailserver.');

            return $value;
        });

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used SMTP username: <comment>' . $value . '</comment>');
    }

    private function setEmailReceiver($input, $output, $helper, &$config, $default, $total_config)
    {
        if ( $total_config['email_notifications'] == false )
            return false;

        $output->writeln('<info>Please set your email address:</info>');

        $question = new Question(
            'Type your email address where you want to receive email notifications'.
            ($default ? ' or press enter for using default email <comment>'.$default.'</comment>' : '').': '
        , null);
        $question->setValidator(function($value) use($default) {
            if ( ! $default && empty($value) )
                throw new \Exception('Please fill email address.');

            return $value;
        });

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used email address: <comment>' . $value . '</comment>');
    }

    private function setEmailPassword($input, $output, $helper, &$config, $default, $total_config)
    {
        if ( $total_config['email_notifications'] == false )
            return false;

        $output->writeln('<info>Please set SMTP password:</info>');

        $question = new Question(
            'Type SMTP password'.
            ($default ? ' or press enter for using default password <comment>'.$default.'</comment>' : '').': '
        , null);
        $question->setValidator(function($value) use ($default) {
            if ( ! $default && empty($value) )
                throw new \Exception('Please fill SMTP password of your account.');

            return $value;
        });

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used SMTP password: <comment>' . $value . '</comment>');
    }

    private function setWWWPath($input, $output, $helper, &$config, $default)
    {
        $output->writeln('<info>Please set WWW path of your websites which you want backup.</info>');

        //Nginx path
        $question = new Question('Type new path or press enter for using default <comment>'.$default.'</comment> path: ', null);
        $question->setValidator(function($path) {
            if ( $path && ! file_exists($path) )
                throw new \Exception('Please fill valid existing path.');

            return trim_end($path, '/');
        });

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used path: <comment>' . $value . '</comment>');
    }

    private function setRemoteServer($input, $output, $helper, &$config, $default, $total_config)
    {
        if ( $total_config['remote_backups'] == false )
            return false;

        $output->writeln('<info>Please set remote server address</info>');

        $question = new Question(
            'Type IP address or domain name of your server where backup data will be stored'.
            ($default ? ' or press enter for using default address <comment>'.$default.'</comment>' : '').': '
        , null);

        $question->setValidator(function($value) use ($default) {
            if ( ! $default && empty($value) )
                throw new \Exception('Please fill valid address of server.');

            return $value;
        });

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used IP/Domain: <comment>' . $value . '</comment>');
    }


    public function setMaxWWWBackups($input, $output, $helper, &$config, $default)
    {
        $question = new Question(
            '<info>How many backups would you like to archive? (0-3)</info> '."\n".
            'Or press enter for using default <comment>'.$default.'</comment> backups: '
        , null);

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Max WWW Backups: <comment>'.$value.'</comment>');
    }

    private function setRemoteBackupLimit($input, $output, $helper, &$config, $default, $total_config)
    {
        if ( $total_config['remote_backups'] == false )
            return false;

        $output->writeln('<info>Please set remote backups limit</info>');

        $question = new Question(
            'Type how many latest backups from local machine should be stored in remote server.'."\n".
            'All previous backups from remote server will be deleted.'."\n".
            'Or press enter for using default <comment>'.$default.'</comment> backups: '
        , null);

        $question->setValidator(function($value) {
            if ( $value && $value !== 0 && !is_numeric($value) )
                throw new \Exception('Please fill valid number of backups.');

            return $value;
        });

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Latest remote backups: <comment>' . $value . '</comment>');
    }

    /*
     * Set remote user
     */
    private function setRemoteUser($input, $output, $helper, &$config, $default, $total_config)
    {
        if ( $total_config['remote_backups'] == false )
            return false;

        $output->writeln('<info>Please set user of remote server</info>');

        $question = new Question('Type linux user of remote backup server or press enter for using default <comment>'.$default.'</comment>: ', null);

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used User: <comment>' . $value . '</comment>');

        $this->generateRSA($total_config);
    }

    /*
     * Generate SSH keys for remote backups if does not exists
     */
    private function generateRSA($total_config)
    {
        $rsa_dir = $total_config['backup_path'].'/.ssh';
        $rsa_path = $rsa_dir.'/id_rsa';

        //If rsa keys does not exists
        if ( ! file_exists($rsa_path) )
        {
            $this->output->writeln('<info>Generating RSA keys into:</info> '.$rsa_dir);
            exec('mkdir -p '.$rsa_dir);
            exec('ssh-keygen -t rsa -q -N "" -f '.$rsa_path);
            file_put_contents($rsa_dir.'/authorized_keys', '# Paste here your public key from remote server');
        }

        $this->output->writeln(
            'Add this public key to your remote server into <info>'.$rsa_dir.'/authorized_keys</info> file: '."\n".
            '<comment>'.trim(file_get_contents($rsa_path.'.pub')).'</comment>'
        );
    }

    private function setRemoteBackupPath($input, $output, $helper, &$config, $default, $total_config)
    {
        if ( $total_config['remote_backups'] == false )
            return false;

        $default = $default ?: '/var/vpsmanager_backups/remote/'.$total_config['backup_server_name'];

        $output->writeln('<info>Please set backup path in remote server where will be stored all backups of your local resources.</info>');

        $question = new Question('Type new path or press enter for using default remote <comment>'.$default.'</comment> destination path: ', null);

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used Path: <comment>' . $value . '</comment>');
    }

    private function setBackupPath($input, $output, $helper, &$config, $default)
    {
        $output->writeln('<info>Please set backup path where will be stored all local backups of your resources.</info>');

        $question = new Question('Type new path or press enter for using default <comment>'.$default.'</comment> path: ', null);

        $value = $config = trim_end($helper->ask($input, $output, $question) ?: $default, '/');

        $this->createUserForBackupDirectory($value);
        $this->setBackupDirectoryPermissions($value);
    }

    /*
     * Create user which will owns backup directory
     */
    private function createUserForBackupDirectory($value)
    {
        if ( ! vpsManager()->server()->existsUser($this->default_backup_user) )
        {
            exec('useradd -s /bin/bash -d '.$value.' -U '.$this->default_backup_user, $output, $return_var);

            if ( $return_var != 0 )
                throw new \Exception('Directory user '.$this->default_backup_user.' could not be created.');

            $this->output->writeln('User created: <comment>'.$this->default_backup_user.'</comment>');
        }
    }

    /*
     * Create user which will owns backup directory
     */
    private function setBackupDirectoryPermissions($path)
    {
        exec('mkdir -p '.$path);
        exec('chmod 700 -R '.$path);
        exec('chown '.$this->default_backup_user.':'.$this->default_backup_user.' -R '.$path);

        $this->output->writeln('Directory used and permissions changed: <comment>'.$path.'</comment>');
    }

    private function setBackupDirectories($input, $output, $helper, &$config, $default)
    {
        $output->writeln('<info>Please set which additional directories should be backed up.</info>');

        $question = new Question(
            'Type multiple paths separated with <comment>;</comment> in format <comment>path1;path2;path3</comment>.'."\n".
            'If you don\'t want backup any directories, then press <comment>-</comment> and hit enter.'."\n"
           .'Or press enter for using default <comment>'.$default.'</comment> paths: ', null);

        $value = $config = trim_end($helper->ask($input, $output, $question) ?: $default, '/');

        $output->writeln('Used paths: <comment>' . $value . '</comment>');
    }

    private function addIntoCrontab($input, $output, $helper, &$config, $default)
    {
        $question = new ConfirmationQuestion('<info>Would you like to run this backups at</info> <comment>4AM</comment><info>? Crontab will be added. (y/N)</info> ', $default);

        $value = $config = $helper->ask($input, $output, $question);

        $output->writeln('Crontab: <comment>'.($value ? 'ON' : 'OFF').'</comment>');

        $app_path = implode('/', array_slice(explode('/', __DIR__), 0, -3));
        $line = '0 4 * * * php '.$app_path.'/vpsmanager backup:run';

        if ( $config == false )
            return $output->writeln('You can add crontab manually later: <info>'.$line.'</info>');

        $crontab_path = '/var/spool/cron/crontabs/root';
        $crontab_data = file_get_contents($crontab_path);

        //If crontab does not exists already
        if ( strpos($crontab_data, 'vpsmanager backup:run') === false )
        {
            @file_put_contents($crontab_path, $line."\n", FILE_APPEND);
            $output->writeln('Crontab has been added at: <comment>4AM</comment>');
        }

        //Crontab exists
        else {
            $output->writeln('Crontab for backups exists already.');
        }
    }
}