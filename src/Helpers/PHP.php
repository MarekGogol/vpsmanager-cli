<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;
use Gogol\VpsManagerCLI\Traits\PHPSettingsTrait;

class PHP extends Application
{
    use PHPSettingsTrait;

    /*
     * Check if is php version installed
     */
    public function isInstalled($version, $php_path = null)
    {
        if (!$this->isValidPHPVersion($version)) {
            return false;
        }

        return file_exists(($php_path ?: $this->config('php_path')) . '/' . $version);
    }

    /*
     * Return path to command
     */
    public function getPhpBinPath($version)
    {
        return '/usr/bin/php' . $version;
    }

    /*
     * Changing default php in CLI
     */
    public function changeDefaultPHP($version)
    {
        exec('update-alternatives --set php ' . $this->getPhpBinPath($version), $output, $return_var);

        return $return_var == 0 ? true : false;
    }

    /*
     * Returns php socket name
     */
    public function getSocketName($domain, $php_version)
    {
        return 'php' . $php_version . '-fpm-' . $this->toUserFormat($domain);
    }

    /*
     * Return pool path
     */
    public function getPoolPath($domain, $php_version)
    {
        return $this->config('php_path') . '/' . $php_version . '/fpm/pool.d/' . $this->toUserFormat($domain) . '.conf';
    }

    /*
     * Check if pool file exists
     */
    public function poolExists($domain, $php_version)
    {
        return file_exists($this->getPoolPath($domain, $php_version));
    }

    /**
     * Create new pool for domain
     * @param  [type] $domain      domain name
     * @param  [type] $php_version version of pgp
     * @return [type]              [description]
     */
    public function createPool($domain, array $config = [])
    {
        $php_version = $config['php_version'];

        $user = $this->toUserFormat($domain);

        if (!in_array($php_version, $this->getVersions())) {
            return $this->response()->error('Zadali ste nesprávnu verziu PHP');
        }

        if (!isValidDomain($domain)) {
            return $this->response()->wrongDomainName();
        }

        if (!$this->isInstalled($php_version)) {
            return $this->response()->error('PHP s verziou ' . $php_version . ' nie je nainštalované.');
        }

        if ($this->poolExists($domain, $php_version)) {
            return $this->response();
        }

        $stub = $this->getStub('php-pool.conf');

        $stub->replace('{{user}}', $user);
        $stub->replace('{{version}}', $php_version);
        $stub->replace('{{socket_name}}', $this->getSocketName($domain, $php_version));

        //Add settings at the end of the pool
        foreach ($this->phpSettings($domain, $config) as $key => $value) {
            $stub->addLine('php_admin_value[' . $key . '] = ' . $value);
        }

        //Save pool
        if (!$stub->save($this->getPoolPath($domain, $php_version))) {
            return $this->response()->error('Súbor pre PHP pool sa nepodarilo uložiť.');
        }

        return $this->response()->success('PHP Pool pre web <info>' . $domain . '</info> bol úspešne vytvorený.');
    }

    /*
     * Remove pool from php configuration
     */
    public function removePool($domain, $php_version)
    {
        if (!isValidDomain($domain) || !$this->isValidPHPVersion($php_version)) {
            return false;
        }

        if (!file_exists($pool_path = $this->getPoolPath($domain, $php_version))) {
            return true;
        }

        return @unlink($pool_path) ? true : false;
    }

    /*
     * Restart nginx
     */
    public function restart($php_version)
    {
        if (!$this->isValidPHPVersion($php_version)) {
            return false;
        }

        exec('service php' . $php_version . '-fpm restart', $output, $return_var);

        return $return_var == 0 ? true : false;
    }
}

?>
