<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

class Certbot extends Application
{
    public function certPath()
    {
        return $this->config('ssl_path') ?: '/etc/letsencrypt/live';
    }

    /*
     * Check if lets encrypt certificate already exists exists
     */
    public function exists(string $domain)
    {
        if ( ! isValidDomain($domain) )
            return false;

        $path = $this->certPath().'/'.$domain;

        return file_exists($path);
    }

    /*
     * Update (non-www to www) redirect with https redirect also with www and non-www version
     */
    private function replaceRedirectSection($domain, $nginx_conf)
    {
        $redirect_section_def = $redirect_section = $this->nginx()->getSection('Default domain redirect (non www to www)', $nginx_conf);

        if ( $redirect_section == false ){
            $this->response()->error('<error>Could not find default redirect section in NGINX configuration '.$this->nginx()->getAvailablePath($domain).'. Please set redirect manually.</error>')->writeln(null, true);

            return $nginx_conf;
        }

        $first_level_domain = $this->toUserFormat($domain);

        $redirect_section = str_replace('server_name '.$first_level_domain.';', "listen 80;\n    listen [::]:80;\n\n".'    server_name '.$first_level_domain.' www.'.$first_level_domain.';', $redirect_section);
        $redirect_section = str_replace('return 301 $scheme://www.'.$first_level_domain.'$request_uri;', 'return 301 https://www.'.$first_level_domain.'$request_uri;', $redirect_section);

        return str_replace($redirect_section_def, $redirect_section, $nginx_conf);
    }

    private function getSSLPaths($domain)
    {
        return "\n".'
    ssl_certificate /etc/letsencrypt/live/'.$domain.'/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/'.$domain.'/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;';
    }

    /*
     * Update default host
     */
    private function replaceDefaultHost($domain, $cert_name, $nginx_conf)
    {
        $host_section_def = $host_section = $this->nginx()->getSection('Default host configuration', $nginx_conf);

        if ( $host_section == false ){
            $this->response()->error('<error>Could not find default host section in NGINX configuration '.$this->nginx()->getAvailablePath($domain).'. Please set HTTPS Listeners and certificates manually.</error>')->writeln(null, true);

            return $nginx_conf;
        }

        $host_section = str_replace('listen 80;', 'listen 443 ssl http2;', $host_section);
        $host_section = str_replace('listen [::]:80;', 'listen [::]:443 ssl http2;'.$this->getSSLPaths($cert_name), $host_section);

        return str_replace($host_section_def, $host_section, $nginx_conf);
    }

    /*
     * Add subdomain redirect to https version
     */
    private function addSubdomainRedirectSection($domain, $nginx_conf)
    {
        //If redirect already exists
        if ( $this->nginx()->getSection('Redirect to https for subdomain '.$domain, $nginx_conf) )
            return $nginx_conf;

        $stub = $this->getStub('nginx.redirect.conf');
        $stub->addLineBefore('# Redirect to https for subdomain '.$domain);

        $stub->replace('server_name {from-host};', "listen 80;\n    listen [::]:80;\n\n".'    server_name '.$domain.';');
        $stub->replace('return 301 $scheme://{to-host}$request_uri;', 'return 301 https://'.$domain.'$request_uri;');

        return $nginx_conf . "\n\n" . $stub;
    }

    /*
     * Add subdomain ssl host
     */
    private function addSubdomainSSLSection($domain, $cert_name, $nginx_conf)
    {
        //If redirect already exists
        if ( $this->nginx()->getSection('HTTPS host for subdomain '.$domain, $nginx_conf) )
            return $nginx_conf;

        $stub = $this->getStub('nginx.template.conf');
        $stub->addLineBefore('# HTTPS host for subdomain '.$domain);
        $stub->replace('{host}', $domain);
        $stub->replace('{path}', $this->getWebPath($domain).'/sub/'.$this->getSubdomain($domain).'/public');
        $stub->replace('{error_log_path}', $this->nginx()->getErrorLogPath($domain));
        $stub->replace('listen 80;', 'listen 443 ssl http2;');
        $stub->replace('listen [::]:80;', 'listen [::]:443 ssl http2;'.$this->getSSLPaths($cert_name));

        preg_match('/php\d([\s\S]*?)\.sock/', $nginx_conf, $matches);

        //Use existing fpm sock name
        $socket_name = count($matches) > 0 ? trim_end($matches[0], '.sock') : $this->php()->getSocketName($domain, $this->config('php_version'));
        $stub->replace('{php_sock_name}', $socket_name);

        return $nginx_conf . "\n\n" . $stub;
    }

    public function create(string $domain)
    {
        if ( ! isValidDomain($domain) )
            return $this->response()->wrongDomainName();

        //Check if certificate exists already
        if ( vpsManager()->certbot()->exists($domain) )
            return $this->response()->error('Let\'s encrypt certificate for this host already exists.');

        $hosts = $this->getSubdomain($domain) ? [ $domain ] : [ $domain, 'www.'.$domain ];

        //Run certbot certificates
        exec($cmd = ('certbot certonly --nginx --agree-tos -n -m '.$this->config('ssl_email').' -d '.implode(' -d ', $hosts)), $output, $return_var);
        $output = implode("\n", $output);

        if ( $return_var != 0 )
            return $this->response()->error('<error>Certificate could not be installed. Please install manualy via:</error><comment>'."\n".$cmd.'</comment>');

        //Get certificates directory name
        preg_match('/'.str_replace('/', '\/', $this->certPath().'/').'(.*)\/fullchain\.pem/', $output, $matches);

        $first_level_domain = $this->toUserFormat($domain);

        //Get nginx host configuration
        $nginx_conf = file_get_contents($this->nginx()->getAvailablePath($domain));

        //If is not subdomain host, then change default domain host and redirect
        if ( ! $this->getSubdomain($domain) )
        {
            //Replace http redirect to https redirect, and also non-www to www in one redirect scope
            $nginx_conf = $this->replaceRedirectSection($domain, $nginx_conf);
            $nginx_conf = $this->replaceDefaultHost($domain, isset($matches[1]) ? $matches[1] : $domain, $nginx_conf);
        }

        //For subdomains add subdomain sections
        else {
            $nginx_conf = $this->addSubdomainRedirectSection($domain, $nginx_conf);
            $nginx_conf = $this->addSubdomainSSLSection($domain, isset($matches[1]) ? $matches[1] : $domain, $nginx_conf);
        }

        //Update config host
        file_put_contents($this->nginx()->getAvailablePath($domain), $nginx_conf);

        $this->hosting()->rebootNginx();

        return $this->response()->success('Certificates for subdomain '.$domain.' has been successfully installed!');
    }
}

?>