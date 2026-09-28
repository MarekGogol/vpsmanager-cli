<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

class Nginx extends Application
{
    /**
     * Check if nginx host of the given domain exists.
     *
     * @param  string  $domain
     * @return bool
     */
    public function exists(string $domain): bool
    {
        if (! isValidDomain($domain)) {
            return false;
        }

        return file_exists($this->getAvailablePath($domain));
    }

    /**
     * Get path of the host configuration in sites-available.
     *
     * @param  string  $domain
     * @return string
     */
    public function getAvailablePath(string $domain): string
    {
        return $this->config('nginx_path').'/sites-available/'.$this->toUserFormat($domain);
    }

    /**
     * Get path of the host configuration in sites-enabled.
     *
     * @param  string  $domain
     * @return string
     */
    public function getEnabledPath(string $domain): string
    {
        return $this->config('nginx_path').'/sites-enabled/'.$this->toUserFormat($domain);
    }

    /**
     * Get path of the error log file.
     *
     * @param  string  $domain
     * @param  array|null  $config
     * @param  string|null  $filename
     * @return string
     */
    public function getErrorLogPath(string $domain, ?array $config = null, ?string $filename = null): string
    {
        return $this->getWebPath($domain, $config).'/logs/'.($filename ?: 'error').'.log';
    }

    /**
     * Copy vpsmanager nginx configuration files into the nginx directory.
     *
     * @return void
     */
    public function cloneNginxSettings(): void
    {
        $nginx_path = $this->config('nginx_path');

        if (file_exists($nginx_path.'/vpsmanager')) {
            return;
        }

        $resources = __DIR__.'/../Resources/nginx';

        exec('cp -Rf '.$resources.'/vpsmanager '.$nginx_path.'/vpsmanager', $output, $return_var);
        exec('cp -f '.$resources.'/nginx.conf '.$nginx_path.'/nginx.conf', $output, $return_var1);
        exec('cp -f '.$resources.'/conf.d/* '.$nginx_path.'/conf.d/', $output, $return_var2);

        if ($return_var === 0 && $return_var1 === 0 && $return_var2 === 0) {
            $this->response()
                ->success('<info>NGINX configuration files have been successfully copied.</info>')
                ->writeln(null, true);
        } else {
            $this->response()
                ->error(
                    '<error>NGINX configuration files could not be copied.</error>'."\n".
                    'Please copy <comment>./Resources/nginx</comment> directory from this package to <comment>/etc/nginx</comment>',
                )
                ->writeln(null, true);
        }
    }

    /**
     * Create new nginx host.
     *
     * @param  string  $domain
     * @param  array  $config
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function createHost(string $domain, array $config = []): Response
    {
        if (! isValidDomain($domain)) {
            return $this->response()->wrongDomainName();
        }

        $php_version = $config['php_version'] ?? null;

        // Check if the given php version is supported
        if (! in_array($php_version, $this->php()->getVersions())) {
            return $this->response()->error('Invalid PHP version given.');
        }

        // Skip creating when nginx host exists
        if ($this->exists($domain)) {
            return $this->response();
        }

        $this->cloneNginxSettings();

        $stub = $this->generateNginxHostStub($domain, $config, $php_version);

        if (! $stub->save($this->getAvailablePath($domain))) {
            return $this->response()->error('NGINX host file could not be saved.');
        }

        if (! $this->allowHost($domain)) {
            return $this->response()->error('Could not create a symlink of the host in the sites-enabled directory.');
        }

        return $this->response()->success('NGINX host <info>'.$domain.'</info> has been successfully created.');
    }

    /**
     * Generate nginx host configuration.
     *
     * @param  string  $domain
     * @param  array  $config
     * @param  string  $php_version
     * @return \Gogol\VpsManagerCLI\Helpers\Stub
     */
    private function generateNginxHostStub(string $domain, array $config, string $php_version): Stub
    {
        $first_level_domain = $this->toUserFormat($domain);

        $www_path = isset($config['www_path'])
            ? $config['www_path'].'/public'
            : $this->getWebPath($domain, $config).'/web/public';

        // Keep clean templates for the subdomain sections
        $stub = $this->getStub('nginx.redirect.conf');
        $redirect_stub = clone $stub;
        $host_stub = $this->getStub('nginx.template.conf');

        // Create redirect from non www to www
        $stub->addLineBefore(
            '# NGINX host configuration for '.$first_level_domain.' by VPS Manager.'."\n".
            '# Please do not delete any comments before server {} sections. Automated scripts are related to these comments.'."\n\n".
            '# Default domain redirect (non www to www)',
        );
        $stub->replace('{from-host}', $first_level_domain);

        // We use $host instead of www.domain, because redirect must point
        // to the same domain first (then to the https version) for enhanced security
        $stub->replace('{to-host}', '$host');

        // Add default nginx host configuration
        $stub->addLine("\n".(clone $host_stub)->addLineBefore('# Default host configuration'));
        $stub->replace('{host}', 'www.'.$first_level_domain);
        $stub->replace('{path}', $www_path);
        $stub->replace('{php_version}', $php_version);
        $stub->replace('{php_sock_name}', $this->php()->getSocketName($domain, $php_version));
        $stub->replace('{error_log_path}', $this->getErrorLogPath($domain, $config));

        $this->addSubdomainSupport($domain, $config, $stub, $host_stub, $redirect_stub, $php_version);

        return $stub;
    }

    /**
     * Add automatic subdomains support into nginx host configuration.
     *
     * @param  string  $domain
     * @param  array  $config
     * @param  \Gogol\VpsManagerCLI\Helpers\Stub  $stub
     * @param  \Gogol\VpsManagerCLI\Helpers\Stub  $sub_stub
     * @param  \Gogol\VpsManagerCLI\Helpers\Stub  $redirect_stub
     * @param  string  $php_version
     * @return void
     */
    private function addSubdomainSupport(string $domain, array $config, Stub $stub, Stub $sub_stub, Stub $redirect_stub, string $php_version): void
    {
        // Custom www path hostings (e.g. manager) do not support auto subdomains
        if (isset($config['www_path'])) {
            return;
        }

        $first_level_domain = $this->toUserFormat($domain);
        $domain_regex = str_replace('.', '\.', $first_level_domain);

        $redirect_stub->replace('{from-host}', '"~^www\.(?<sub>.+)\.'.$domain_regex.'$"');
        $redirect_stub->replace('{to-host}', '$sub.'.$first_level_domain);
        $stub->addLine("\n".$redirect_stub);

        $sub_stub->replace('{host}', '"~^(?<sub>.+)\.'.$domain_regex.'$"');
        $sub_stub->replace('{path}', $this->getWebPath($domain, $config).'/sub/$sub/public');
        $sub_stub->replace('{php_version}', $php_version);
        $sub_stub->replace('{php_sock_name}', $this->php()->getSocketName($domain, $php_version));
        $sub_stub->replace('{error_log_path}', $this->getErrorLogPath($domain, $config));

        $stub->addLine("\n".$sub_stub);
    }

    /**
     * Remove nginx host.
     *
     * @param  string  $domain
     * @return bool
     */
    public function removeHost(string $domain): bool
    {
        if (! isValidDomain($domain)) {
            return false;
        }

        // Use is_link, because broken symlinks are not detected by file_exists
        $enabledPath = $this->getEnabledPath($domain);

        if ((is_link($enabledPath) || file_exists($enabledPath)) && ! @unlink($enabledPath)) {
            return false;
        }

        if (file_exists($availablePath = $this->getAvailablePath($domain)) && ! @unlink($availablePath)) {
            return false;
        }

        return true;
    }

    /**
     * Get nginx server {} section by the comment above it.
     *
     * @param  string  $comment
     * @param  string  $conf
     * @return string|false
     */
    public function getSection(string $comment, string $conf): string|false
    {
        $regex = '#\#\s?'.preg_quote($comment, '#').'\nserver\s?\{[\s\S]*?\n\}#i';

        if (! preg_match($regex, $conf, $matches)) {
            return false;
        }

        return trim($matches[0]);
    }

    /**
     * Enable domain host (symlink into sites-enabled).
     *
     * @param  string  $domain
     * @return bool
     */
    public function allowHost(string $domain): bool
    {
        if (! isValidDomain($domain)) {
            return false;
        }

        if (file_exists($this->getEnabledPath($domain))) {
            return true;
        }

        exec('ln -s '.$this->getAvailablePath($domain).' '.$this->getEnabledPath($domain), $output, $return_var);

        return $return_var === 0;
    }

    /**
     * Check if nginx configuration is valid.
     *
     * @return bool
     */
    public function test(): bool
    {
        exec('nginx -t 2> /dev/null', $output, $return_var);

        // If nginx has an error, test it again to print the error output (stderr)
        if ($return_var !== 0) {
            exec('nginx -t');
        }

        return $return_var === 0;
    }

    /**
     * Restart nginx service.
     *
     * @param  bool  $test_before
     * @return bool
     */
    public function restart(bool $test_before = true): bool
    {
        if ($test_before === true && ! $this->test()) {
            return false;
        }

        exec('service nginx restart', $output, $return_var);

        return $return_var === 0;
    }
}
