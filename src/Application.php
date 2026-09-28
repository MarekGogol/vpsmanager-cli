<?php

namespace Gogol\VpsManagerCLI;

use Gogol\VpsManagerCLI\Helpers\Backup;
use Gogol\VpsManagerCLI\Helpers\Certbot;
use Gogol\VpsManagerCLI\Helpers\Chroot;
use Gogol\VpsManagerCLI\Helpers\Hosting;
use Gogol\VpsManagerCLI\Helpers\Laravel;
use Gogol\VpsManagerCLI\Helpers\MySQLHelper;
use Gogol\VpsManagerCLI\Helpers\Nginx;
use Gogol\VpsManagerCLI\Helpers\PHP;
use Gogol\VpsManagerCLI\Helpers\Response;
use Gogol\VpsManagerCLI\Helpers\Server;
use Gogol\VpsManagerCLI\Helpers\SSH;
use Gogol\VpsManagerCLI\Helpers\Stub;
use Gogol\VpsManagerCLI\Helpers\Supervisor;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/*
 * Main VPS Manager application container.
 *
 * Holds the loaded configuration, the console I/O and lazily booted helper instances.
 */
class Application
{
    /**
     * The booted helper instances.
     *
     * @var array
     */
    public $booted = [];

    /**
     * The loaded configuration.
     *
     * @var array|null
     */
    protected $config = null;

    /**
     * The console output.
     *
     * @var \Symfony\Component\Console\Output\OutputInterface|null
     */
    public $output = null;

    /**
     * The console input.
     *
     * @var \Symfony\Component\Console\Input\InputInterface|null
     */
    public $input = null;

    /**
     * The console question helper.
     *
     * @var \Symfony\Component\Console\Helper\QuestionHelper|null
     */
    public $helper = null;

    /**
     * Get the whole configuration or a single config value.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function config($key = null, $default = null)
    {
        // Boot config params
        $config = vpsManager()->bootConfig();

        if (! $key) {
            return $config;
        }

        if (! array_key_exists($key, $config) || is_null($config[$key])) {
            return $default;
        }

        return $config[$key];
    }

    /**
     * Load configuration data from the config file.
     *
     * @param  bool  $force
     * @return array
     */
    public function bootConfig(bool $force = false): array
    {
        if (! $this->config || $force === true) {
            if (file_exists($path = vpsManagerPath().'/config.php')) {
                $this->config = require $path;
            } else {
                $this->config = [];
            }
        }

        return $this->config;
    }

    /**
     * Save configuration data into the config file.
     *
     * @param  array  $data
     * @return int|false
     */
    public function saveConfig(array $data): int|false
    {
        $path = vpsManagerPath().'/config.php';

        $save = file_put_contents($path, "<?php \n\nreturn ".var_export($data, true).';');

        // Make the config readable just for root
        exec('chown root:root '.$path);
        exec('chmod 600 '.$path);

        return $save;
    }

    /**
     * Boot console in VPS Manager and check correct permissions.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface|null  $output
     * @param  \Symfony\Component\Console\Input\InputInterface|null  $input
     * @param  \Symfony\Component\Console\Helper\QuestionHelper|null  $helper
     * @return void
     *
     * @throws \Exception
     */
    public function bootConsole(?OutputInterface $output, ?InputInterface $input = null, ?QuestionHelper $helper = null): void
    {
        if ($output) {
            $this->output = $output;
        }

        if ($input) {
            $this->input = $input;
        }

        if ($helper) {
            $this->helper = $helper;
        }

        checkPermissions();
    }

    /**
     * Get the console output.
     *
     * @return \Symfony\Component\Console\Output\OutputInterface|null
     */
    public function getOutput(): ?OutputInterface
    {
        return $this->output;
    }

    /**
     * Get the console input.
     *
     * @return \Symfony\Component\Console\Input\InputInterface|null
     */
    public function getInput(): ?InputInterface
    {
        return $this->input;
    }

    /**
     * Get a stub instance.
     *
     * @param  string  $name
     * @return \Gogol\VpsManagerCLI\Helpers\Stub
     */
    public function getStub($name): Stub
    {
        return new Stub($name);
    }

    /**
     * Boot the given helper class only once.
     *
     * @param  string  $namespace
     * @return mixed
     */
    protected function boot($namespace)
    {
        if (array_key_exists($namespace, $this->booted)) {
            return $this->booted[$namespace];
        }

        $this->booted[$namespace] = new $namespace();
        $this->booted[$namespace]->booted = $this->booted;

        return $this->booted[$namespace];
    }

    /**
     * Get a new response instance.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function response(): Response
    {
        return new Response();
    }

    /**
     * Get the hosting helper.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\Hosting
     */
    public function hosting(): Hosting
    {
        return $this->boot(Hosting::class);
    }

    /**
     * Get the backup helper.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\Backup
     */
    public function backup(): Backup
    {
        return $this->boot(Backup::class);
    }

    /**
     * Get the server helper.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\Server
     */
    public function server(): Server
    {
        return $this->boot(Server::class);
    }

    /**
     * Get the chroot helper.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\Chroot
     */
    public function chroot(): Chroot
    {
        return $this->boot(Chroot::class);
    }

    /**
     * Get the NGINX helper.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\Nginx
     */
    public function nginx(): Nginx
    {
        return $this->boot(Nginx::class);
    }

    /**
     * Get the SSH helper.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\SSH
     */
    public function ssh(): SSH
    {
        return $this->boot(SSH::class);
    }

    /**
     * Get the Certbot helper.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\Certbot
     */
    public function certbot(): Certbot
    {
        return $this->boot(Certbot::class);
    }

    /**
     * Get the PHP helper.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\PHP
     */
    public function php(): PHP
    {
        return $this->boot(PHP::class);
    }

    /**
     * Get the supervisor helper.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\Supervisor
     */
    public function supervisor(): Supervisor
    {
        return $this->boot(Supervisor::class);
    }

    /**
     * Get the Laravel helper.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\Laravel
     */
    public function laravel(): Laravel
    {
        return $this->boot(Laravel::class);
    }

    /**
     * Get the MySQL helper.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\MySQLHelper
     */
    public function mysql(): MySQLHelper
    {
        return $this->boot(MySQLHelper::class);
    }

    /**
     * Get the subdomain part of a third level domain.
     *
     * @param  string  $domain
     * @return string|false
     */
    public function getSubdomain($domain): string|false
    {
        if (count($parts = $this->getDomainParts($domain)) == 3) {
            return $parts[0];
        }

        return false;
    }

    /**
     * Split the domain into its parts.
     *
     * @param  string  $domain
     * @return array
     */
    public function getDomainParts($domain): array
    {
        return explode('.', $domain);
    }

    /**
     * Get the web data directory name inside the user directory.
     *
     * @return string
     */
    public function getWebDirectory(): string
    {
        return '/data';
    }

    /**
     * Get the user directory path of the given domain.
     *
     * @param  string  $domain
     * @param  array|null  $config
     * @return string
     */
    public function getUserDirPath($domain, $config = null): string
    {
        if (isset($config['www_path'])) {
            return $config['www_path'];
        }

        return $this->config('www_path').'/'.$this->toUserFormat($domain);
    }

    /**
     * Get the web data path of the given domain.
     *
     * @param  string  $domain
     * @param  array|null  $config
     * @return string
     */
    public function getWebPath($domain, $config = null): string
    {
        if (isset($config['www_path'])) {
            return $config['www_path'];
        }

        return $this->config('www_path').'/'.$this->toUserFormat($domain).$this->getWebDirectory();
    }

    /**
     * Convert the domain into the user format (removes subdomains).
     *
     * @param  string  $domain
     * @return string
     */
    public function toUserFormat($domain): string
    {
        $parts = explode('.', $domain);

        return implode('.', array_slice($parts, -2));
    }
}
