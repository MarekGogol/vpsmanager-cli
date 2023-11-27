<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

class Nginx extends Application
{
    /*
     * Check if domain exists
     */
    public function exists(string $domain)
    {
        if (!isValidDomain($domain)) {
            return false;
        }

        $path = $this->config('nginx_path') . '/sites-available/' . $this->toUserFormat($domain);

        return file_exists($path);
    }

    public function getAvailablePath($domain)
    {
        return $this->config('nginx_path') . '/sites-available/' . $this->toUserFormat($domain);
    }

    public function getEnabledPath($domain)
    {
        return $this->config('nginx_path') . '/sites-enabled/' . $this->toUserFormat($domain);
    }

    public function getErrorLogPath($domain, $config = null, $filename = null)
    {
        return $this->getWebPath($domain, $config) . '/logs/' . ($filename ?: 'error') . '.log';
    }

    public function cloneNginxSettings()
    {
        $nginx_path = $this->config('nginx_path');

        if (!file_exists($nginx_path . '/vpsmanager')) {
            exec('cp -Rf ' . __DIR__ . '/../Resources/nginx/vpsmanager ' . $nginx_path . '/vpsmanager', $output, $return_var);
            exec('cp -f ' . __DIR__ . '/../Resources/nginx/nginx.conf ' . $nginx_path . '/nginx.conf', $output1, $return_var1);
            exec('cp -f ' . __DIR__ . '/../Resources/nginx/conf.d/* ' . $nginx_path . '/conf.d/', $output1, $return_var2);

            if ($return_var == 0 && $return_var1 == 0 && $return_var2 == 0) {
                $this->response()
                    ->success('<info>NGINX configuration files has been successfully copied.</info>')
                    ->writeln(null, true);
            } else {
                $this->response()
                    ->error(
                        '<error>NGINX configuration files could not be copied.</error>' .
                            "\n" .
                            'Please copy <comment>./Resources/nginx</comment> directory from this package to <comment>/etc/nginx</comment>',
                    )
                    ->writeln(null, true);
            }
        }
    }

    /**
     * Create new host
     * @param  string $domain
     * @param  array  $config [php_version]
     * @return [type]
     */
    public function createHost(string $domain, array $config = [])
    {
        if (!isValidDomain($domain)) {
            return $this->response()->wrongDomainName();
        }

        //Check if is correct setted php verion
        if (!in_array($php_version = $config['php_version'], $this->php()->getVersions())) {
            return $this->response()->error('Zadali ste nesprávnu verziu PHP');
        }

        //Skip creating when nginx exists
        if ($this->exists($domain)) {
            return $this->response();
        }

        $this->cloneNginxSettings();

        $stub = $this->generateNginxHostStub($domain, $config, $php_version);

        if (!$stub->save($this->getAvailablePath($domain))) {
            return $this->response()->error('Súbor NGINX host sa nepodarilo uložiť.');
        }

        if (!$this->allowHost($domain)) {
            return $this->response()->error('Nepodarilo sa vytvoriť odkaz na host v priečinku sites-enabled.');
        }

        return $this->response()->success('NGINX host <info>' . $domain . '</info> bol úspešne vytvorený.');
    }

    private function generateNginxHostStub($domain, $config, $php_version)
    {
        $www_path = isset($config['www_path']) ? $config['www_path'] . '/public' : $this->getWebPath($domain, $config) . '/web/public';

        //Create redirect from non www to www
        $redirect_stub = clone ($stub = $this->getStub('nginx.redirect.conf'));
        $stub->addLineBefore(
            '# NGINX host configuration for ' .
                $this->toUserFormat($domain) .
                ' by VPS Manager.' .
                "\n" .
                '# Please do not delete any comments before server {} sections. Automated scripts are related to this comments.' .
                "\n\n" .
                '# Default domain redirect (non www to www)',
        );
        $stub->replace('{from-host}', $this->toUserFormat($domain));
        $stub->replace('{to-host}', 'www.' . $this->toUserFormat($domain));

        //Add default nginx host configuration
        $stub->addLine("\n" . (clone ($host_stub = $this->getStub('nginx.template.conf')))->addLineBefore('# Default host configuration'));
        $stub->replace('{host}', 'www.' . $this->toUserFormat($domain));
        $stub->replace('{path}', $www_path);
        $stub->replace('{php_version}', $php_version);
        $stub->replace('{php_sock_name}', $this->php()->getSocketName($domain, $php_version));
        $stub->replace('{error_log_path}', $this->getErrorLogPath($domain, $config));

        $this->addSubdomainSupport($domain, $config, $stub, $host_stub, $redirect_stub, $php_version);

        return $stub;
    }

    private function addSubdomainSupport($domain, $config, $stub, $sub_stub, $redirect_stub, $php_version)
    {
        //If is regular hosting, then allow auto subdomains
        if (isset($config['www_path'])) {
            return;
        }

        $first_level_domain = $this->toUserFormat($domain);

        $redirect_stub->replace('{from-host}', '"~^www\.(?<sub>.+)\.' . str_replace('.', '\.', $first_level_domain) . '$"');
        $redirect_stub->replace('{to-host}', '$sub.' . $first_level_domain);
        $stub->addLine("\n" . $redirect_stub);

        $sub_stub->replace('{host}', '"~^(?<sub>.+)\.' . str_replace('.', '\.', $first_level_domain) . '$"');
        $sub_stub->replace('{path}', $this->getWebPath($domain, $config) . '/sub/$sub/public');
        $sub_stub->replace('{php_version}', $php_version);
        $sub_stub->replace('{php_sock_name}', $this->php()->getSocketName($domain, $php_version));
        $sub_stub->replace('{error_log_path}', $this->getErrorLogPath($domain, $config));

        $stub->addLine("\n" . $sub_stub);
    }

    public function removeHost($domain)
    {
        if (!isValidDomain($domain)) {
            return false;
        }

        if (file_exists($this->getEnabledPath($domain)) && !@unlink($this->getEnabledPath($domain))) {
            return false;
        }

        if (file_exists($this->getAvailablePath($domain)) && !@unlink($this->getAvailablePath($domain))) {
            return false;
        }

        return true;
    }

    /*
     * Return nginx section by comments
     */
    public function getSection($comment, $conf)
    {
        $regex = '#\#\s?' . preg_quote($comment) . '\nserver\s?\{[\s\S]*?\n\}#i';
        preg_match($regex, $conf, $matches);

        if (count($matches) == 0) {
            return false;
        }

        return trim($matches[0]);
    }

    /*
     * Allow domain host
     */
    public function allowHost(string $domain)
    {
        if (!isValidDomain($domain)) {
            return false;
        }

        if (file_exists($this->getEnabledPath($domain))) {
            return true;
        }

        exec('ln -s ' . $this->getAvailablePath($domain) . ' ' . $this->getEnabledPath($domain), $output, $return_var);

        return $return_var == 0 ? true : false;
    }

    /*
     * Check if configuration is ok
     */
    public function test()
    {
        exec('nginx -t 2> /dev/null', $output, $return_var);

        //If nginx has error, then test it again with response
        if ($return_var != 0) {
            exec('nginx -t');
        }

        return $return_var == 0 ? true : false;
    }

    /*
     * Restart nginx
     */
    public function restart($test_before = true)
    {
        if ($test_before === true && !$this->test()) {
            return false;
        }

        exec('service nginx restart', $output, $return_var);

        return $return_var == 0 ? true : false;
    }
}

?>
