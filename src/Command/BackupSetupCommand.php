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

        //Set config properties
        foreach ([
            'setBackupPath' => [
                'config_key' => 'backup_path',
                'default' => '/root/backups'
            ],
            'setBackupDirectories' => [
                'config_key' => 'backup_directories',
                'default' => '/etc/nginx;/etc/mysql'
            ],
        ] as $method => $data)
        {
            $output->writeln('');
            $this->{$method}($input, $output, $helper, $config[$data['config_key']], $data['default'], $config);
        }

        if ( ! file_put_contents(vpsManagerPath().'/config.php', "<?php \n\nreturn " . var_export($config, true) . ';') )
            throw new \Exception('Setup failed. Config could not be saved into '.vpsManagerPath().'/config.php');

        //Forced booting config
        vpsManager()->bootConfig(true);
    }

    private function setBackupPath($input, $output, $helper, &$config, $default)
    {
        $output->writeln('<info>Please set backup path where will be stored all backups of your resources.</info>');

        //Nginx path
        $question = new Question('Type new path or press enter for using default <comment>'.$default.'</comment> path: ', null);

        $value = $config = trim_end($helper->ask($input, $output, $question) ?: $default, '/');

        $output->writeln('Used path: <comment>' . $value . '</comment>');
    }

    private function setBackupDirectories($input, $output, $helper, &$config, $default)
    {
        $output->writeln('<info>Please set which directories should be backed up.</info>');

        //Nginx path
        $question = new Question(
            'Type multiple paths separated with <comment>;</comment> in format <comment>path1;path2;path3</comment>'."\n"
           .'or press enter for using default <comment>'.$default.'</comment> paths: ', null);

        $value = $config = trim_end($helper->ask($input, $output, $question) ?: $default, '/');

        $output->writeln('Used paths: <comment>' . $value . '</comment>');
    }
}