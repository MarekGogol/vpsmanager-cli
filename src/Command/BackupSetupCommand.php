<?php

namespace Gogol\VpsManagerCLI\Command;

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

        $output->writeln("\n".'<info>Backup setup has been successfully completed.</info>');
    }

    public function setConfig($input, $output, $helper)
    {
        $config = vpsManager()->config();

        $config_data = [
            'setBackupPath' => [
                'config_key' => 'backup_path',
                'default' => '/var/vpsmanager_backups'
            ],
            'setBackupDirectories' => [
                'config_key' => 'backup_directories',
                'default' => '/etc/nginx;/etc/mysql'
            ],
            'setRemoteBackups' => [
                'config_key' => 'backup_remote',
                'default' => true,
            ],
            'setRemoteServer' => [
                'config_key' => 'remote_server',
                'default' => null,
            ],
            'setRemoteUser' => [
                'config_key' => 'remote_user',
                'default' => $this->default_backup_user,
            ],
            'setRemoteBackupPath' => [
                'config_key' => 'remote_path',
                'default' => '/var/vpsmanager_backups/remote',
            ],
        ];

        //Set config properties
        foreach ($config_data as $method => $data)
        {
            $output->writeln('');

            $this->{$method}(
                $input,
                $output,
                $helper,
                $config[$data['config_key']],
                $data['default'],
                $config
            );
        }

        if ( ! file_put_contents(vpsManagerPath().'/config.php', "<?php \n\nreturn " . var_export($config, true) . ';') )
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

    private function setRemoteServer($input, $output, $helper, &$config, $default, $total_config)
    {
        if ( $total_config['backup_remote'] == false )
            return;

        $output->writeln('<info>Please set remote server address</info>');

        $question = new Question('Type IP address or domain name of your server where backup data will be stored: ', null);
        $question->setValidator(function($value) {
            if ( empty($value) )
                throw new \Exception('Please fill valid address of server.');

            return $value;
        });

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used IP/Domain: <comment>' . $value . '</comment>');
    }

    /*
     * Set remote user
     */
    private function setRemoteUser($input, $output, $helper, &$config, $default, $total_config)
    {
        if ( $total_config['backup_remote'] == false )
            return;

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
            $this->output->writeln('<info>Generating RSAbackup_path keys into:</info> '.$rsa_dir);
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
        if ( $total_config['backup_remote'] == false )
            return;

        $output->writeln('<info>Please set backup path in remote server where will be stored all backups of your resources.</info>');

        $question = new Question('Type new path or press enter for using default remote <comment>'.$default.'</comment> path: ', null);

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used Path: <comment>' . $value . '</comment>');
    }

    private function setBackupPath($input, $output, $helper, &$config, $default)
    {
        $output->writeln('<info>Please set backup path where will be stored all backups of your resources.</info>');

        $question = new Question('Type new path or press enter for using default <comment>'.$default.'</comment> path: ', null);

        $value = $config = trim_end($helper->ask($input, $output, $question) ?: $default, '/');

        $this->createUserForBackupDirectory();
        $this->setBackupDirectoryPermissions($value);
    }

    /*
     * Create user which will owns backup directory
     */
    private function createUserForBackupDirectory()
    {
        if ( ! vpsManager()->server()->existsUser($this->default_backup_user) )
        {
            exec('useradd -s /bin/bash -d '.vpsManager()->config('backup_path').' -U '.$this->default_backup_user, $output, $return_var);

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
        exec('mkdir -p -m 700 '.$path);
        exec('chown '.$this->default_backup_user.':'.$this->default_backup_user.' -R '.vpsManager()->config('backup_path'));

        $this->output->writeln('Directory used and permissions changed: <comment>'.$path.'</comment>');
    }

    private function setBackupDirectories($input, $output, $helper, &$config, $default)
    {
        $output->writeln('<info>Please set which directories should be backed up.</info>');

        $question = new Question(
            'Type multiple paths separated with <comment>;</comment> in format <comment>path1;path2;path3</comment>'."\n"
           .'or press enter for using default <comment>'.$default.'</comment> paths: ', null);

        $value = $config = trim_end($helper->ask($input, $output, $question) ?: $default, '/');

        $output->writeln('Used paths: <comment>' . $value . '</comment>');
    }
}