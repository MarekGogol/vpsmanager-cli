<?php

namespace Gogol\VpsManagerCLI\Command;

use Exception;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class InstallManagerCommand extends Command
{
    /**
     * The console input.
     *
     * @var \Symfony\Component\Console\Input\InputInterface
     */
    private $input;

    /**
     * The console output.
     *
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    private $output;

    /**
     * The question helper.
     *
     * @var \Symfony\Component\Console\Helper\QuestionHelper
     */
    private $helper;

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('install')
            ->setDescription('Install VPS Manager')
            ->addOption('vpsmanager_path', null, InputOption::VALUE_OPTIONAL, 'Set absolute path of VPS Manager web interface', null)
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Set host path for VPS Manager web interface', null)
            ->addOption('open_basedir', null, InputOption::VALUE_OPTIONAL, 'Allow open_basedir path for VPS Manager web interface', null)
            ->addOption('no_chmod', null, InputOption::VALUE_OPTIONAL, 'Disable change of chmod settings of web directory', null);
    }

    /**
     * Execute the console command.
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

        $this->input = $input;
        $this->output = $output;
        $this->helper = $helper = $this->getHelper('question');

        $output->writeln('');

        $this->setConfig($input, $output, $helper);

        $output->writeln('<info>Installation of</info> <comment>VPS Manager</comment> <info>has been successfully completed.</info>');

        return Command::SUCCESS;
    }

    /**
     * Ask for all config values and save the config file.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @return void
     *
     * @throws \Exception
     */
    public function setConfig(InputInterface $input, OutputInterface $output, QuestionHelper $helper): void
    {
        $vm = vpsManager();
        $config = $vm->config();

        $this->createCommandShortcut();

        $settings = [
            'setNginxPath' => ['nginx_path', '/etc/nginx'],
            'setIsProxiedNginx' => ['nginx_is_proxied', false],
            'setPHPPath' => ['php_path', '/etc/php'],
            'setSSLPath' => ['ssl_path', '/etc/letsencrypt/live'],
            'setSSLEmail' => ['ssl_email', 'noreply@marekgogol.sk'],
            'setDefaultPHPVersion' => ['php_version', '8.5'],
            'setWWWPath' => ['www_path', '/var/www'],
            'enableSelfSignedSSL' => ['self_signed_ssl', true],
            'setMysqlUser' => ['mysql_user', 'root'],
            'setMysqlPassword' => ['mysql_pass', ''],
            'setMysqlHost' => ['mysql_host', 'localhost'],
        ];

        // Set config properties
        foreach ($settings as $method => [$key, $default]) {
            $default = $vm->config($key, $default);

            // Get config inputs
            $config[$key] ??= null;

            $this->{$method}($input, $output, $helper, $config[$key], $default, $config);

            $output->writeln('');
        }

        if (! $vm->saveConfig($config)) {
            throw new Exception('Installation failed. Config could not be saved into '.vpsManagerPath().'/config.php');
        }

        // Force config reload
        $vm->bootConfig(true);
    }

    /**
     * Add the vpsmanager alias into .bashrc file.
     *
     * @return void
     */
    private function createCommandShortcut(): void
    {
        $bashrcFile = trim((string) shell_exec('cd ~ && pwd')).'/.bashrc';

        $vpsmanagerCLIPath = realpath(__DIR__.'/../../vpsmanager');

        $command = 'alias vpsmanager="php '.$vpsmanagerCLIPath.'"';

        // Add alias only if it has not been set yet
        if (! file_exists($bashrcFile) || ! str_contains(file_get_contents($bashrcFile), $command)) {
            @file_put_contents($bashrcFile, "#VPS Manager shortcut command\n$command\n", FILE_APPEND);
        }
    }

    /**
     * Create a question for an existing directory path.
     *
     * @param  string  $default
     * @return \Symfony\Component\Console\Question\Question
     */
    private function createPathQuestion($default): Question
    {
        $question = new Question('Type new path or press enter to use default <comment>'.$default.'</comment> path: ', null);
        $question->setValidator(function ($path) {
            if ($path && ! file_exists($path)) {
                throw new Exception('Please enter a valid existing path.');
            }

            return trim_end($path, '/');
        });

        return $question;
    }

    /**
     * Ask for the NGINX path.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @return void
     */
    private function setNginxPath($input, $output, $helper, &$config, $default): void
    {
        $output->writeln('<info>Please set NGINX path.</info>');

        $value = $config = $helper->ask($input, $output, $this->createPathQuestion($default)) ?: $default;

        $output->writeln('Used path: <comment>'.$value.'</comment>');
    }

    /**
     * Ask for the PHP path.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @return void
     */
    private function setPHPPath($input, $output, $helper, &$config, $default): void
    {
        $output->writeln('<info>Please set PHP path.</info>');

        $value = $config = $helper->ask($input, $output, $this->createPathQuestion($default)) ?: $default;

        $output->writeln('Used path: <comment>'.$value.'</comment>');
    }

    /**
     * Ask for the SSL certificates path.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @return void
     */
    private function setSSLPath($input, $output, $helper, &$config, $default): void
    {
        $output->writeln('<info>Please set SSL certificates path.</info>');

        $value = $config = $helper->ask($input, $output, $this->createPathQuestion($default)) ?: $default;

        $output->writeln('Used path: <comment>'.$value.'</comment>');
    }

    /**
     * Ask for the email used for SSL certificates.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @return void
     */
    private function setSSLEmail($input, $output, $helper, &$config, $default): void
    {
        $output->writeln('<info>Please set email for SSL certificates generation.</info>');

        $question = new Question(
            'Type email address for generating SSL certificate via certbot'.($default ? ' or press enter to use default address <comment>'.$default.'</comment>' : '').': ',
            null,
        );

        $question->setValidator(function ($email) {
            if ($email && ! isValidEmail($email)) {
                throw new Exception('Please enter a valid email address.');
            }

            return $email;
        });

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used email: <comment>'.$value.'</comment>');
    }

    /**
     * Ask for the default PHP version and set it as default PHP CLI version.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @param  array  $full_config
     * @return void
     */
    private function setDefaultPHPVersion($input, $output, $helper, &$config, $default, $full_config): void
    {
        $output->writeln('<info>Please set default PHP version.</info>');

        $php = vpsManager()->php();

        $question = new ChoiceQuestion(
            'Set default PHP version of your server. Default is <comment>'.$default.'</comment>: ',
            $php->getVersions(),
            $default,
        );

        $version = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used version for new websites: <comment>'.$version.'</comment>');

        // Check if PHP version is installed
        if (! $php->isInstalled($version, $full_config['php_path'] ?? null)) {
            $output->writeln('<error>PHP '.$version.' is not installed. Default PHP CLI version has not been changed.</error>');

            return;
        }

        if ($php->changeDefaultPHP($version)) {
            $output->writeln('Updated php alias to: <comment>'.$php->getPhpBinPath($version).'</comment>');
        } else {
            $output->writeln('<error>PHP symlink could not be updated on path '.$php->getPhpBinPath($version).'</error>');
        }
    }

    /**
     * Ask for the WWW path of websites.
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
        $output->writeln('<info>Please set WWW path of your websites.</info>');

        $value = $config = $helper->ask($input, $output, $this->createPathQuestion($default)) ?: $default;

        $output->writeln('Used path: <comment>'.$value.'</comment>');
    }

    /**
     * Ask for the MySQL root user name.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @return void
     */
    private function setMysqlUser($input, $output, $helper, &$config, $default): void
    {
        $output->writeln('<info>Please set MySQL root user name for future MySQL modifications.</info>');

        $question = new Question('Type MySQL root user name <comment>'.$default.'</comment>: ', null);

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used username: <comment>'.$value.'</comment>');
    }

    /**
     * Ask for the MySQL root password.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @return void
     */
    private function setMysqlPassword($input, $output, $helper, &$config, $default): void
    {
        $output->writeln('<info>Please set MySQL root password for future MySQL modifications.</info>');

        $question = new Question('Type MySQL root password'.($default ? ' or press enter to keep the current one' : '').': ', null);

        $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used password: <comment>'.($config ? str_repeat('*', 8) : '(empty)').'</comment>');
    }

    /**
     * Ask for the MySQL host of created users.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @return void
     */
    private function setMysqlHost($input, $output, $helper, &$config, $default): void
    {
        $output->writeln('<info>Please set MySQL host in case of remote connections (localhost, 192.168.1.%, %).</info>');

        $question = new Question('Type MySQL hostname <comment>'.$default.'</comment>: ', null);

        $value = $config = $helper->ask($input, $output, $question) ?: $default;

        $output->writeln('Used host: <comment>'.$value.'</comment>');
    }

    /**
     * Get the VPS Manager web interface host.
     *
     * @return string|null
     */
    private function getManagerHost(): ?string
    {
        return vpsManager()->config('host');
    }

    /**
     * Get the path of VPS Manager web interface.
     *
     * @return string
     */
    private function getManagerPath(): string
    {
        $path = vpsManager()->config('vpsmanager_path');

        // Remove vendor path if the installation has been initialized from vendor directory
        $path = trim_end($path, '/');
        $path = trim_end($path, '/vendor/marekgogol/vpsmanager/src/app');

        return $path;
    }

    /**
     * Ask whether self signed SSL certificates should be enabled in NGINX.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @return void
     */
    private function enableSelfSignedSSL($input, $output, $helper, &$config, $default): void
    {
        $question = new ConfirmationQuestion('<info>Would you like to allow self signed SSL certificates in NGINX?</info> ('.($default ? 'Y/n' : 'y/N').') ', (bool) $default);

        if (! ($config = $helper->ask($input, $output, $question))) {
            return;
        }

        // Enable self signed certs in sites-available/default
        vpsManager()->certbot()->enableDefaultSSLCert();

        $command = 'make-ssl-cert generate-default-snakeoil --force-overwrite';

        $certPath = '/etc/ssl/certs/ssl-cert-snakeoil.pem';
        $keyPath = '/etc/ssl/private/ssl-cert-snakeoil.key';

        if (file_exists($certPath) && file_exists($keyPath)) {
            return;
        }

        exec($command, $commandOutput, $return_var);

        if ($return_var == 0) {
            $output->writeln('SSL snakeoil certificate has been created: '.$certPath);
        } else {
            $output->writeln('<error>SSL snakeoil certificate does not exist and could not be created at: '.$certPath.'</error>'."\n".'<info>Run command:</info> '.$command);
        }
    }

    /**
     * Ask whether NGINX is behind a load balancer receiving proxied SSL requests.
     *
     * @param  \Symfony\Component\Console\Input\InputInterface  $input
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Symfony\Component\Console\Helper\QuestionHelper  $helper
     * @param  mixed  $config
     * @param  mixed  $default
     * @return void
     */
    private function setIsProxiedNginx($input, $output, $helper, &$config, $default): void
    {
        $question = new ConfirmationQuestion('<info>Is this server behind a load balancer and will it receive proxied SSL requests?</info> ('.($default ? 'Y/n' : 'y/N').') ', (bool) $default);

        $config = $helper->ask($input, $output, $question);
    }
}
