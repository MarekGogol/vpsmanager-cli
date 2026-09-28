<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;
use Gogol\VpsManagerCLI\Traits\PHPSettingsTrait;

class PHP extends Application
{
    use PHPSettingsTrait;

    /**
     * Determine if the given PHP version is installed.
     *
     * @param  string  $version
     * @param  string|null  $php_path
     * @return bool
     */
    public function isInstalled($version, $php_path = null): bool
    {
        if (! $this->isValidPHPVersion($version)) {
            return false;
        }

        return file_exists(($php_path ?: $this->config('php_path')).'/'.$version);
    }

    /**
     * Get the path to the PHP binary of the given version.
     *
     * @param  string  $version
     * @return string
     */
    public function getPhpBinPath($version): string
    {
        return '/usr/bin/php'.$version;
    }

    /**
     * Change the default PHP version used in CLI.
     *
     * @param  string  $version
     * @return bool
     */
    public function changeDefaultPHP($version): bool
    {
        exec('update-alternatives --set php '.$this->getPhpBinPath($version), $output, $return_var);

        return $return_var == 0;
    }

    /**
     * Get the PHP-FPM socket name of the given domain.
     *
     * @param  string  $domain
     * @param  string  $php_version
     * @return string
     */
    public function getSocketName($domain, $php_version): string
    {
        return 'php'.$php_version.'-fpm-'.$this->toUserFormat($domain);
    }

    /**
     * Get the PHP-FPM pool config path of the given domain.
     *
     * @param  string  $domain
     * @param  string  $php_version
     * @return string
     */
    public function getPoolPath($domain, $php_version): string
    {
        return $this->config('php_path').'/'.$php_version.'/fpm/pool.d/'.$this->toUserFormat($domain).'.conf';
    }

    /**
     * Determine if the pool file of the given domain exists.
     *
     * @param  string  $domain
     * @param  string  $php_version
     * @return bool
     */
    public function poolExists($domain, $php_version): bool
    {
        return file_exists($this->getPoolPath($domain, $php_version));
    }

    /**
     * Create a new PHP-FPM pool for the given domain.
     *
     * @param  string  $domain
     * @param  array  $config
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function createPool($domain, array $config = []): Response
    {
        $php_version = $config['php_version'] ?? null;

        $user = $this->toUserFormat($domain);

        if (! $this->isValidPHPVersion($php_version)) {
            return $this->response()->error('Invalid PHP version has been given.');
        }

        if (! isValidDomain($domain)) {
            return $this->response()->wrongDomainName();
        }

        if (! $this->isInstalled($php_version)) {
            return $this->response()->error('PHP version '.$php_version.' is not installed.');
        }

        if ($this->poolExists($domain, $php_version)) {
            return $this->response();
        }

        $stub = $this->getStub('php-pool.conf');

        $stub->replace('{{user}}', $user);
        $stub->replace('{{version}}', $php_version);
        $stub->replace('{{socket_name}}', $this->getSocketName($domain, $php_version));

        // Add settings at the end of the pool
        foreach ($this->phpSettings($domain, $config) as $key => $value) {
            $stub->addLine('php_admin_value['.$key.'] = '.$value);
        }

        // Save pool
        if (! $stub->save($this->getPoolPath($domain, $php_version))) {
            return $this->response()->error('PHP pool file could not be saved.');
        }

        return $this->response()->success('PHP pool for website <info>'.$domain.'</info> has been successfully created.');
    }

    /**
     * Remove the pool of the given domain from the PHP configuration.
     *
     * @param  string  $domain
     * @param  string  $php_version
     * @return bool
     */
    public function removePool($domain, $php_version): bool
    {
        if (! isValidDomain($domain) || ! $this->isValidPHPVersion($php_version)) {
            return false;
        }

        if (! file_exists($pool_path = $this->getPoolPath($domain, $php_version))) {
            return true;
        }

        return @unlink($pool_path);
    }

    /**
     * Restart PHP-FPM service of the given version.
     *
     * @param  string  $php_version
     * @return bool
     */
    public function restart($php_version): bool
    {
        if (! $this->isValidPHPVersion($php_version)) {
            return false;
        }

        exec('service php'.$php_version.'-fpm restart', $output, $return_var);

        return $return_var == 0;
    }
}
