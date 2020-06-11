<?php

namespace Gogol\VpsManagerCLI;

use Gogol\VpsManagerCLI\Helpers\Backup;
use Gogol\VpsManagerCLI\Helpers\Certbot;
use Gogol\VpsManagerCLI\Helpers\Chroot;
use Gogol\VpsManagerCLI\Helpers\Hosting;
use Gogol\VpsManagerCLI\Helpers\MySQLHelper;
use Gogol\VpsManagerCLI\Helpers\Nginx;
use Gogol\VpsManagerCLI\Helpers\PHP;
use Gogol\VpsManagerCLI\Helpers\Response;
use Gogol\VpsManagerCLI\Helpers\SSH;
use Gogol\VpsManagerCLI\Helpers\Server;
use Gogol\VpsManagerCLI\Helpers\Stub;

class Application
{
    /*
     * Booted classes
     */
    public $booted = [];

    /*
     * Config properties
     */
    protected $config = null;

    /*
     * Console properties
     */
    public $output = null;
    public $input = null;
    public $helper = null;


    /*
     * Return config
     */
    public function config($key = null, $default = null)
    {
        //Boot config params
        $config = vpsManager()->bootConfig();

        return $key ? (
            array_key_exists($key, $config)
                ? (is_null($config[$key]) ? $default : $config[$key])
                : $default
        ) : $config;
    }

    /*
     * Boot config data from config file
     */
    public function bootConfig($force = false)
    {
        if ( ! $this->config || $force === true )
        {
            if ( file_exists($path = vpsManagerPath() . '/config.php') )
                $this->config = require($path);
            else
                $this->config = [];
        }

        return $this->config;
    }

    public function saveConfig($data)
    {
        $path = vpsManagerPath().'/config.php';

        $save = file_put_contents($path, "<?php \n\nreturn " . var_export($data, true) . ';');

        //Change permissions of config just for root
        exec('chown root:root '.$path);
        exec('chmod 600 '.$path);

        return $save;
    }

    /*
     * Boot console in vpsManager and check correct permissions
     */
    public function bootConsole($output, $input = null, $helper = null)
    {
        if ( $output )
            $this->output = $output;

        if ( $input )
            $this->input = $input;

        if ( $helper )
            $this->helper = $helper;

        checkPermissions();
    }

    /*
     * Get console output
     */
    public function getOutput()
    {
        return $this->output;
    }

    /*
     * Get console output
     */
    public function getInput()
    {
        return $this->input;
    }

    /*
     * Return stub
     */
    public function getStub($name)
    {
        return new Stub($name);
    }

    protected function boot($namespace)
    {
        if ( array_key_exists($namespace, $this->booted) )
            return $this->booted[$namespace];

        $this->booted[$namespace] = new $namespace;
        $this->booted[$namespace]->booted = $this->booted;

        return $this->booted[$namespace];
    }

    /*
     * Return response helper
     */
    public function response()
    {
        return new Response;
    }

    /*
     * Return hosting helper
     */
    public function hosting()
    {
        return $this->boot(Hosting::class);
    }

    /*
     * Return backup helper
     */
    public function backup()
    {
        return $this->boot(Backup::class);
    }

    /*
     * Return hosting helper
     */
    public function server()
    {
        return $this->boot(Server::class);
    }

    /*
     * Return hosting helper
     */
    public function chroot()
    {
        return $this->boot(Chroot::class);
    }

    /*
     * Return NGINX helper
     */
    public function nginx()
    {
        return $this->boot(Nginx::class);
    }

    /*
     * Return ssh helper
     */
    public function ssh()
    {
        return $this->boot(SSH::class);
    }

    /*
     * Return Certbot helper
     */
    public function certbot()
    {
        return $this->boot(Certbot::class);
    }

    /*
     * Return PHP helper
     */
    public function php()
    {
        return $this->boot(PHP::class);
    }

    /*
     * Return PHP helper
     */
    public function mysql()
    {
        return $this->boot(MySQLHelper::class);
    }

    public function getSubdomain($domain)
    {
        if ( count($parts = $this->getDomainParts($domain)) == 3 )
            return $parts[0];

        return false;
    }

    public function getDomainParts($domain)
    {
        return explode('.', $domain);
    }

    public function getWebDirectory()
    {
        return '/data';
    }

    /*
     * Return web path
     */
    public function getUserDirPath($domain, $config = null)
    {
        if ( isset($config['www_path']) )
            return $config['www_path'];

        return $this->config('www_path') . '/' . $this->server()->toUserFormat($domain);
    }

    /*
     * Return web path
     */
    public function getWebPath($domain, $config = null)
    {
        if ( isset($config['www_path']) )
            return $config['www_path'];

        return $this->config('www_path')
               .'/'.$this->server()->toUserFormat($domain)
               .$this->getWebDirectory($config);
    }

    /*
     * From domain to user format
     * removes subdomains
     */
    public function toUserFormat($domain)
    {
        $parts = explode('.', $domain);

        return implode('.', array_slice($parts, -2));
    }
}