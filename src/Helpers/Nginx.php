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
     * Copy vpsmanager configuration files added after the installation. Existing files are kept,
     * they may be edited on the server, except the given files fully managed by vpsmanager, which
     * are written when they are missing or differ, also outside of the vpsmanager directory.
     *
     * @param  array  $managed  paths relative to the NGINX directory, e.g. vpsmanager/scanners.conf
     * @param  bool  $dryRun  only return the files which would be written
     * @return array relative path => previous content (null for a new file)
     */
    public function syncNginxSettings(array $managed = [], bool $dryRun = false): array
    {
        $resources = __DIR__.'/../Resources/nginx';
        $paths = array_map(fn ($file) => 'vpsmanager/'.basename($file), glob($resources.'/vpsmanager/*'));
        $written = [];

        foreach (array_unique([...$paths, ...$managed]) as $relative) {
            $source = $resources.'/'.$relative;
            $path = $this->config('nginx_path').'/'.$relative;
            $exists = file_exists($path);

            if ($exists && (! in_array($relative, $managed) || file_get_contents($path) === file_get_contents($source))) {
                continue;
            }

            $previous = $exists ? file_get_contents($path) : null;

            if ($dryRun || copy($source, $path)) {
                $written[$relative] = $previous;
            }
        }

        return $written;
    }

    /**
     * Remove vpsmanager configuration files fully managed by vpsmanager, e.g. when the monitor is removed.
     * New hosts recognize the monitor by these files, so they must not stay on the server.
     *
     * @param  array  $managed  paths relative to the NGINX directory, e.g. vpsmanager/scanners.conf
     * @param  bool  $dryRun  only return the files which would be removed
     * @return array relative path => previous content
     */
    public function removeNginxSettings(array $managed, bool $dryRun = false): array
    {
        $removed = [];

        foreach ($managed as $relative) {
            $path = $this->config('nginx_path').'/'.$relative;

            if (! file_exists($path)) {
                continue;
            }

            $previous = file_get_contents($path);

            if ($dryRun || @unlink($path)) {
                $removed[$relative] = $previous;
            }
        }

        return $removed;
    }

    /**
     * Files of the monitor generated for this server, relative path => content. Servers behind a router (nginx_is_proxied)
     * send the blocked and refused requests to syslog of the router and know the real address of the visitors also
     * in sections without general.conf (e.g. the default server). Other servers get the files with a comment only.
     *
     * @param  array  $paths  e.g. vpsmanager/router-scanners.conf
     * @return array
     */
    public function getMonitorGeneratedFiles(array $paths): array
    {
        $router = vpsManager()->router();
        $header = "# Generated by VPS Manager (monitor:install) for this server, changes are overwritten.\n\n";
        $files = [];

        foreach ($paths as $path) {
            $files[$path] = $header.match ($path) {
                'vpsmanager/router-scanners.conf' => ($syslog = $router->getNginxSyslog('vpsm_scanners'))
                    ? "# Blocked requests for fail2ban of the router in front of this server\naccess_log ".$syslog." vpsmanager_scanners;\n"
                    : "# This server is not behind a router, fail2ban of the server bans the scanners\n",
                'vpsmanager/router-auth.conf' => ($syslog = $router->getNginxSyslog('vpsm_auth'))
                    ? "# Requests refused by auth_basic for fail2ban of the router in front of this server\naccess_log ".$syslog." vpsmanager_scanners if=\$vpsmanager_auth_failed;\n"
                    : "# This server is not behind a router, fail2ban of the server bans the addresses guessing passwords\n",
                'conf.d/vpsmanager-realip.conf' => $router->isEnabled() ? $this->getRealIpConfiguration() : "# This server is not behind a router\n",
            };
        }

        return $files;
    }

    /**
     * Real address of the visitors from proxy_protocol of the router, for all sections of the http context (also
     * the default server, which has no general.conf). Connections without proxy_protocol keep their address.
     *
     * @return string
     */
    protected function getRealIpConfiguration(): string
    {
        // Proxies of general.conf, otherwise the network of the router
        $proxies = vpsManager()->fail2ban()->getTrustedProxies() ?: array_filter([vpsManager()->router()->getNetwork()]);

        return "# Real address of the visitors, sent by the router in front of this server\nreal_ip_header proxy_protocol;\n"
            .implode('', array_map(fn ($proxy) => 'set_real_ip_from '.$proxy.";\n", $proxies));
    }

    /**
     * Write the generated files when they differ.
     *
     * @param  array  $files  relative path => content
     * @param  bool  $dryRun  only return the files which would be written
     * @return array relative path => previous content (null for a new file)
     */
    public function writeGeneratedFiles(array $files, bool $dryRun = false): array
    {
        $written = [];

        foreach ($files as $relative => $content) {
            $path = $this->config('nginx_path').'/'.$relative;
            $previous = file_exists($path) ? file_get_contents($path) : null;

            if ($previous === $content) {
                continue;
            }

            if ($dryRun || file_put_contents($path, $content) !== false) {
                $written[$relative] = $previous;
            }
        }

        return $written;
    }

    /**
     * Restore vpsmanager configuration files written by syncNginxSettings() or removed by removeNginxSettings().
     *
     * @param  array  $written  relative path => previous content (null for a new file)
     * @return void
     */
    public function restoreNginxSettings(array $written): void
    {
        foreach ($written as $relative => $previous) {
            $path = $this->config('nginx_path').'/'.$relative;

            if ($previous === null) {
                @unlink($path);
            } else {
                file_put_contents($path, $previous);
            }
        }
    }

    /**
     * Get paths of the configuration files of all enabled hosts.
     *
     * @return array
     */
    public function getEnabledHostPaths(): array
    {
        $paths = [];

        foreach (glob($this->config('nginx_path').'/sites-enabled/*') as $path) {
            if ($real = realpath($path)) {
                $paths[] = $real;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Include the vpsmanager configuration file in every server section of the host which serves an application:
     * after general.conf, or at the end of the section without it (e.g. Nuxt or other proxied applications).
     * Sections only redirecting (no location) and sections serving WordPress keep their configuration.
     *
     * @param  string  $conf
     * @param  string  $file  e.g. scanners.conf
     * @param  bool  $skipWordpress  WordPress sections keep their configuration
     * @return string
     */
    public function addVpsManagerInclude(string $conf, string $file, bool $skipWordpress = true): string
    {
        return $this->mapServerSections($conf, function ($section) use ($file, $skipWordpress) {
            if (str_contains($section, 'vpsmanager/'.$file) || ($skipWordpress && str_contains($section, 'vpsmanager/wordpress.conf'))) {
                return $section;
            }

            if (preg_match('#^[ \t]*include vpsmanager/general\.conf;$#m', $section)) {
                return preg_replace(
                    '#^([ \t]*)include vpsmanager/general\.conf;$#m',
                    '$0'."\n".'$1include vpsmanager/'.$file.';',
                    $section,
                    1,
                );
            }

            if (! preg_match('#^[ \t]*location\s#m', $section)) {
                return $section;
            }

            // The rules run before any location, so the end of the section is as good as any other place
            return preg_replace('#\n\}$#', "\n\n    include vpsmanager/".$file.";\n}", rtrim($section));
        });
    }

    /**
     * Include the vpsmanager configuration file right after the include of another one, in every server section
     * which has it and is not protected by a password (auth_basic of the server level). The include is removed
     * from the other sections, e.g. when a password has been added to the section.
     *
     * @param  string  $conf
     * @param  string  $file  e.g. scanners-php.conf
     * @param  string  $after  e.g. scanners.conf
     * @return string
     */
    public function syncVpsManagerIncludeAfter(string $conf, string $file, string $after): string
    {
        return $this->mapServerSections($conf, function ($section) use ($file, $after) {
            $protected = false;

            $this->mapServerLevelLines($section, function ($line) use (&$protected) {
                $protected = $protected || preg_match('#^[ \t]*auth_basic[ \t]+(?!off;)\S#', $line);

                return $line;
            });

            $included = str_contains($section, 'vpsmanager/'.$file);

            if ($protected || ! str_contains($section, 'vpsmanager/'.$after)) {
                return $included ? $this->removeVpsManagerInclude($section, $file) : $section;
            }

            if ($included) {
                return $section;
            }

            return preg_replace(
                '#^([ \t]*)include vpsmanager/'.preg_quote($after, '#').';$#m',
                '$0'."\n".'$1include vpsmanager/'.$file.';',
                $section,
                1,
            );
        });
    }

    /**
     * Add the includes of the monitor to the host, the same as monitor:install does for all hosts: rules against
     * scanners, rules against .php files (not in sections with a password) and the access log. Only the parts
     * installed on the server are added, so new hosts and sections (hosting:create, hosting:ssl, laravel:octane)
     * are protected and logged without running monitor:install again, and servers without the monitor keep
     * a valid configuration.
     *
     * @param  string  $conf
     * @return string
     */
    public function addMonitorIncludes(string $conf): string
    {
        $path = $this->config('nginx_path');

        // The rules need their log format of conf.d
        if (file_exists($path.'/conf.d/vpsmanager-scanners.conf') && file_exists($path.'/vpsmanager/scanners.conf')) {
            $conf = $this->addVpsManagerInclude($conf, 'scanners.conf');

            if (file_exists($path.'/vpsmanager/scanners-php.conf')) {
                $conf = $this->syncVpsManagerIncludeAfter($conf, 'scanners-php.conf', 'scanners.conf');
            }
        }

        // The access log needs its log format and maps of conf.d
        if (file_exists($path.'/conf.d/vpsmanager-monitor.conf') && file_exists($path.'/vpsmanager/monitor.conf')) {
            $conf = $this->disableServerAccessLogOff($this->addVpsManagerInclude($conf, 'monitor.conf', false), 'monitor.conf');
        }

        return $conf;
    }

    /**
     * Remove the include of the vpsmanager configuration file from all server sections of the host.
     *
     * @param  string  $conf
     * @param  string  $file  e.g. scanners.conf
     * @return string
     */
    public function removeVpsManagerInclude(string $conf, string $file): string
    {
        // A blank line before the include belongs to it, when it was added at the end of a section
        return preg_replace('#\n(?:[ \t]*\n)?[ \t]*include vpsmanager/'.preg_quote($file, '#').';[ \t]*(?=\n)#', '', $conf);
    }

    /**
     * Comment out "access_log off" of the server level in the sections including the given file, it would cancel
     * the access log of the file. "access_log off" of locations (e.g. static files) stays.
     *
     * @param  string  $conf
     * @param  string  $file  e.g. monitor.conf
     * @return string
     */
    public function disableServerAccessLogOff(string $conf, string $file): string
    {
        return $this->mapServerSections($conf, function ($section) use ($file) {
            if (! str_contains($section, 'vpsmanager/'.$file)) {
                return $section;
            }

            return $this->mapServerLevelLines($section, function ($line) use ($file) {
                return preg_replace('#^([ \t]*)access_log off;[ \t]*$#', '$1# access_log off; (replaced by vpsmanager/'.$file.')', $line);
            });
        });
    }

    /**
     * Bring back "access_log off" commented out by disableServerAccessLogOff().
     *
     * @param  string  $conf
     * @param  string  $file  e.g. monitor.conf
     * @return string
     */
    public function restoreServerAccessLogOff(string $conf, string $file): string
    {
        return preg_replace('#^([ \t]*)\# access_log off; \(replaced by vpsmanager/'.preg_quote($file, '#').'\)$#m', '$1access_log off;', $conf);
    }

    /**
     * Call the callback with every line of the server {} section which is not nested in a block (location, if...).
     *
     * @param  string  $section
     * @param  callable  $callback
     * @return string
     */
    private function mapServerLevelLines(string $section, callable $callback): string
    {
        $depth = 0;
        $lines = explode("\n", $section);

        foreach ($lines as $i => $line) {
            // Comments do not open or close blocks
            $code = preg_replace('/#.*$/', '', $line);

            if ($depth === 1) {
                $lines[$i] = $callback($line);
            }

            $depth += substr_count($code, '{') - substr_count($code, '}');
        }

        return implode("\n", $lines);
    }

    /**
     * Call the callback with every server {} section of the configuration.
     *
     * @param  string  $conf
     * @param  callable  $callback
     * @return string
     */
    private function mapServerSections(string $conf, callable $callback): string
    {
        return preg_replace_callback('#(?<=^|\n)server\s?\{[\s\S]*?\n\}#', fn ($matches) => $callback($matches[0]), $conf);
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

        if (file_put_contents($this->getAvailablePath($domain), $this->addMonitorIncludes((string) $stub)) === false) {
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

        // Plain http redirects to the www host. After SSL is set up, Certbot rewrites
        // this redirect to https on the same host first, for enhanced security (HSTS)
        $stub->replace('{to-host}', 'www.'.$first_level_domain);

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
     * Reload nginx configuration without dropping open connections.
     *
     * @return bool
     */
    public function reload(): bool
    {
        if (! $this->test()) {
            return false;
        }

        exec('service nginx reload', $output, $return_var);

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
