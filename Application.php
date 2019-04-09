<?php

namespace Gogol\VpsManager\App;

use Gogol\VpsManager\App\Helpers\Certbot;
use Gogol\VpsManager\App\Helpers\Hosting;
use Gogol\VpsManager\App\Helpers\MySQL;
use Gogol\VpsManager\App\Helpers\Nginx;
use Gogol\VpsManager\App\Helpers\PHP;
use Gogol\VpsManager\App\Helpers\Response;
use Gogol\VpsManager\App\Helpers\Server;
use Gogol\VpsManager\App\Helpers\Stub;

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
    public function config($key = null)
    {
        //Boot config params
        $config = vpsManager()->bootConfig();

        return $key ? (array_key_exists($key, $config) ? $config[$key] : null) : $config;
    }

    /*
     * Boot config data from config file
     */
    public function bootConfig($force = false)
    {
        if ( ! $this->config || $force === true )
            $this->config = require(vpsManagerPath() . '/config.php');

        return $this->config;
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
     * Return hosting helper
     */
    public function server()
    {
        return $this->boot(Server::class);
    }

    /*
     * Return NGINX helper
     */
    public function nginx()
    {
        return $this->boot(Nginx::class);
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
        return $this->boot(MySQL::class);
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

    /*
     * Return web path
     */
    public function getWebPath($domain, $config = null)
    {
        if ( isset($config['www_path']) )
            return $config['www_path'];

        return $this->config('www_path') . '/' . $this->server()->toUserFormat($domain);
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