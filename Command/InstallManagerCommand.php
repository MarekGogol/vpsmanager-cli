<?php

namespace Gogol\VpsManager\App\Command;

use Gogol\VpsManager\App\Nginx\Nginx;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
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

        $output->writeln('');

        $helper = $this->getHelper('question');

        $this->setConfig($input, $output, $helper);

        // $this->generateManagerHosting($input, $output);

        $output->writeln('');
        $output->writeln('<info>Installation of</info> <comment>VPS Manager</comment> <info>has been successfully completed.</info>');
    }

    public function isDev()
    {
        return $this->input->getOption('dev') == 1;
    }

    public function setConfig($input, $output, $helper)
    {
        $config = [];

        //Set config properties
        foreach ([
            'setNginxPath' => [
                'config_key' => 'nginx_path',
                'default' => '/etc/nginx'
            ],
            'setPHPPath' => [
                'config_key' => 'php_path',
                'default' => '/etc/php'
            ],
            'setSSLPath' => [
                'config_key' => 'ssl_path',
                'default' => '/etc/letsencrypt/live'
            ],
            'setSSLEmail' => [
                'config_key' => 'ssl_email',
                'default' => null,
            ],
            'setDefaultPHPVersion' => [
                'config_key' => 'php_version',
                'default' => '7.2'
            ],
            // 'setVpsManagerPath' => [
            //     'config_key' => 'vpsmanager_path',
            //     'default' => $input->getOption('vpsmanager_path') ?: null,
            // ],
            'setWWWPath' => [
                'config_key' => 'www_path',
                'default' => '/var/www'
            ],
            // 'setHost' => [
            //     'config_key' => 'host',
            //     'default' => $input->getOption('host') ?: 'vpsmanager.example.com'
            // ]
        ] as $method => $data)
        {
            //Use default config values
            if ( $this->isDev() )
                $config[$data['config_key']] = $data['default'];

            //Get config inputs
            else {
                $output->writeln('');
                $this->{$method}($input, $output, $helper, $config[$data['config_key']], $data['default'], $config);
            }
        }

        if ( ! file_put_contents(vpsManagerPath().'/config.php', "<?php \n\nreturn " . var_export($config, true) . ';') )
            throw new \Exception('Installation failed. Config could not be saved into '.vpsManagerPath().'/config.php');

        //Forced booting config
        vpsManager()->bootConfig(true);
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
        $question = new Question('Type email adress for generating SSL certificate via certbot: ', null);
        $question->setValidator(function($email) {
            if ( ! isValidEmail($email) )
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
}