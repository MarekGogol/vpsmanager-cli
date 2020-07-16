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

class InstallManagerCommand extends Command
{
    private $input;
    private $output;

    protected function configure()
    {
        $this->setName('install')
             ->setDescription('Install VPS Manager')
             ->addOption('dev', null, InputOption::VALUE_OPTIONAL, 'Use dev version of installation', null)
             ->addOption('vpsmanager_path', null, InputOption::VALUE_OPTIONAL, 'Set absolute path of VPS Manager web interface', null)
             ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Set host path for VPS Manager web interface', null)
             ->addOption('open_basedir', null, InputOption::VALUE_OPTIONAL, 'Allow open_basedir path for VPS Manager web interface', null)
             ->addOption('no_chmod', null, InputOption::VALUE_OPTIONAL, 'Disable change of chmod settings of web directory', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        vpsManager()->bootConsole($output);

        $this->input = $input;
        $this->output = $output;
        $this->helper = $helper = $this->getHelper('question');

        $output->writeln('');

        $this->setConfig($input, $output, $helper);

        // $this->generateManagerHosting($input, $output);

        $output->writeln('<info>Installation of</info> <comment>VPS Manager</comment> <info>has been successfully completed.</info>');
    }

    public function isDev()
    {
        return $this->input->getOption('dev') == 1;
    }

    public function setConfig($input, $output, $helper)
    {
        $vm = vpsManager();
        $config = $vm->config();

        $this->createCommandShortcut();

        //Set config properties
        foreach ([
            'setNginxPath' => [
                'config_key' => $k = 'nginx_path',
                'default' => $vm->config($k, '/etc/nginx')
            ],
            'setPHPPath' => [
                'config_key' => $k = 'php_path',
                'default' => $vm->config($k, '/etc/php')
            ],
            'setSSLPath' => [
                'config_key' => $k = 'ssl_path',
                'default' => $vm->config($k, '/etc/letsencrypt/live')
            ],
            'setSSLEmail' => [
                'config_key' => $k = 'ssl_email',
                'default' => $vm->config($k, null)
            ],
            'setDefaultPHPVersion' => [
                'config_key' => $k = 'php_version',
                'default' => $vm->config($k, '7.2')
            ],
            'setWWWPath' => [
                'config_key' => $k = 'www_path',
                'default' => $vm->config($k, '/var/www')
            ],
            'enableSelfSignedSSL' => [
                'config_key' => $k = 'self_signed_ssl',
                'default' => $vm->config($k, true)
            ],
            'setMysqlUser' => [
                'config_key' => $k = 'mysql_user',
                'default' => $vm->config($k, 'root')
            ],
            'setMysqlPassword' => [
                'config_key' => $k = 'mysql_pass',
                'default' => $vm->config($k, '')
            ],
            // 'setVpsManagerPath' => [
            //     'config_key' => $k = 'vpsmanager_path',
            //     'default' => $vm->config($k, $input->getOption('vpsmanager_path') ?: null)
            // ],
            // 'setHost' => [
            //     'config_key' => $k = 'host',
            //     'default' => $vm->config($k, $input->getOption('host') ?: 'vpsmanager.example.com')
            // ]
        ] as $method => $data)
        {
            //Use default config values
            if ( $this->isDev() )
                $config[$data['config_key']] = $data['default'];

            //Get config inputs
            else {
                $this->{$method}($input, $output, $helper, $config[$data['config_key']], $data['default'], $config);
                $output->writeln('');
            }
        }

        if ( ! vpsManager()->saveConfig($config) ){
            throw new \Exception('Installation failed. Config could not be saved into '.vpsManagerPath().'/config.php');
        }

        //Forced booting config
        vpsManager()->bootConfig(true);
    }

    private function createCommandShortcut()
    {
        $bashrcFile = trim(shell_exec('cd ~ && pwd')).'/.bashrc';

        $vpsmanagerCLIPath = realpath(__DIR__.'/../../vpsmanager');

        $command = 'alias vpsmanager="php '.$vpsmanagerCLIPath.'"';

        //If command alias has not been setls
        if ( !file_exists($bashrcFile) || strpos(file_get_contents($bashrcFile), $command) === false ) {
            @file_put_contents($bashrcFile, "#VPS Manager shortcut command\n$command\n", FILE_APPEND);
        }
    }

    private function setNginxPath($input, $output, $helper, &$config, $default)
    {
        $output->writeln('<info>Please set NGINX path.</info>');

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

    private function setPHPPath($input, $output, $helper, &$config, $default)
    {
        $output->writeln('<info>Please set PHP path.</info>');

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

    private function setSSLPath($input, $output, $helper, &$config, $default)
    {
        $output->writeln('<info>Please set SSL ceriticates path.</info>');

        //SSL path
        $question = new Question('Type new path or press enter for using default <comment>'.$default.'</comment> path: ', null);
        $question->setValidator(function($path) {
            if ( $path && ! file_exists($path) )
                throw new \Exception('Please fill valid existing path.');

            return trim_end($path, '/');
        });

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used path: <comment>' . $value . '</comment>');
    }

    private function setSSLEmail($input, $output, $helper, &$config, $default)
    {
        $output->writeln('<info>Please set Email for SSL ceriticates generation.</info>');

        //Nginx path
        $question = new Question(
            'Type email adress for generating SSL certificate via certbot'.
            ($default ? ' or press enter for using default address <comment>'.$default.'</comment>' : '').': '
        , null);
        $question->setValidator(function($email) use($default) {
            if ( !$default && !isValidEmail($email) )
                throw new \Exception('Please fill valid email address.');

            return $email;
        });

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used email: <comment>' . $value . '</comment>');
    }

    private function setDefaultPHPVersion($input, $output, $helper, &$config, $default, $full_config)
    {
        $output->writeln('<info>Please set default PHP version.</info>');

        //Nginx path
        $question = new ChoiceQuestion('Set default PHP Version of your server. Default is <comment>'.$default.'</comment>: ', vpsManager()->php()->getVersions(), $default);

        $version = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used version for new websites: <comment>' . $version . '</comment>');

        //Check if is PHP Version installed
        if ( ($php = vpsManager()->php())->isInstalled($version, $full_config['php_path']) )
        {
            if ( $php->changeDefaultPHP($version) )
                $output->writeln('Updated php alias to: <comment>' . $php->getPhpBinPath($version) . '</comment>');
            else
                $output->writeln('<error>PHP symlink could not be updated on path ' . $php->getPhpBinPath($version) . '</error>');
        } else {
            throw new \Exception('Required PHP Version is not installed.');
        }
    }

    private function setVpsManagerPath($input, $output, $helper, &$config, $default)
    {
        $output->writeln('<info>Please set VPSManager web interface path (path to Laravel app without /public).</info>');

        //Nginx path
        $question = new Question('Type new path or press enter for using default <comment>'.$default.'</comment> path: ', null);
        $question->setValidator(function($path) use ($default) {
            if ( $path && ! file_exists($path) || ! $path && ! $default )
                throw new \Exception('Please fill valid existing path.');

            return trim_end($path, '/');
        });

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used path: <comment>' . $value . '</comment>');
    }

    private function setWWWPath($input, $output, $helper, &$config, $default)
    {
        $output->writeln('<info>Please set WWW path of your websites.</info>');

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

    private function setMysqlUser($input, $output, $helper, &$config, $default)
    {
        $output->writeln('<info>Please set MYSQL root user name for future mysql modifications.</info>');

        //Nginx path
        $question = new Question('Type mysql root user name <comment>'.$default.'</comment>: ', null);

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used username: <comment>' . $value . '</comment>');
    }

    private function setMysqlPassword($input, $output, $helper, &$config, $default)
    {
        $output->writeln('<info>Please set MYSQL password for future mysql modifications.</info>');

        //Nginx path
        $question = new Question('Type mysql root password <comment>'.$default.'</comment>: ', null);
        $question->setValidator(function($pass) use ($default) {
            if ( !$pass && ! $default ){
                throw new \Exception('Please fill valid password.');
            }

            return $pass;
        });

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used password: <comment>' . $value . '</comment>');
    }

    private function setHost($input, $output, $helper, &$config, $default)
    {
        $output->writeln('<info>Please set host of your VPSManager admin panel.</info>');

        //Nginx path
        $question = new Question("eg. vpsmanager.example.com: ", null);

        $question->setValidator(function($host) {
            if ( ! $host || ! isValidDomain($host) )
                throw new \Exception('Please fill valid host name.');

            return $host;
        });

        $value = $config = ($helper->ask($input, $output, $question) ?: $default);

        $output->writeln('Used host: <comment>' . $value . '</comment>');
    }

    /*
     * Returns manager host
     */
    private function getManagerHost()
    {
        return vpsManager()->config('host');
    }

    /*
     * Return path of manager web interface
     */
    private function getManagerPath()
    {
        $path = vpsManager()->config('vpsmanager_path');

        //If installation process was initialized from vendor directory, then remove this path from vpsmanager path
        $path = trim_end($path, '/');
        $path = trim_end($path, '/vendor/marekgogol/vpsmanager/src/app');

        return $path;
    }

    /*
     * Set host
     */
    private function generateManagerHosting($input, $output)
    {
        $host_name = $this->getManagerHost();

        //Reset settings for manager web interface
        if ( $this->isDev($input) )
            vpsManager()->hosting()->remove($host_name);

        if ( ($response = vpsManager()->hosting()->create($host_name, [
            'www_path' => $this->getManagerPath(),
            'open_basedir' => $input->getOption('open_basedir'),
            'no_chmod' => $input->getOption('no_chmod'),
        ]))->isError() )
            throw new \Exception($response->message);

        $output->writeln('<info>'.$response->message.'</info>');
    }

    private function enableSelfSignedSSL($input, $output, $helper, &$config, $default)
    {
        $question = new ConfirmationQuestion('<info>Would you like to allow self signed SSL certificates in NGINX?</info> (y/N) ', $default);

        if ( !($config = $helper->ask($input, $output, $question)) )
            return;

        //Enable self signed certs in sites-available/default
        vpsManager()->certbot()->enableDefaultSSLCert();

        $command = 'make-ssl-cert generate-default-snakeoil --force-overwrite';
        $generate = "\n".'<info>run command:</info> '.$command;

        if ( ! file_exists($path = '/etc/ssl/certs/ssl-cert-snakeoil.pem') )
        {
            exec($command, $_output, $return_var);

            if ( $return_var == 0 )
                $output->writeln('SSL Snakeoil certificate has been created: '.$path);
            else
                return $output->writeln('<error>SSL Snakeoil certificate does not exists and could not be created at: '.$path.'</error>'.$generate);
        }

        if ( ! file_exists($path = '/etc/ssl/private/ssl-cert-snakeoil.key') )
        {
            exec($command, $_output, $return_var);

            if ( $return_var == 0 )
                $output->writeln('SSL Snakeoil certificate has been created: '.$path);
            else
                return $output->writeln('<error>SSL Snakeoil certificate does not exists and could not be created at: '.$path.'</error>'.$generate);
        }
    }
}