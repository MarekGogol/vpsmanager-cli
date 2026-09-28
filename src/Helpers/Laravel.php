<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

class Laravel extends Application
{
    /**
     * First port assigned to Octane servers.
     *
     * @var int
     */
    protected int $octaneFirstPort = 8100;

    /**
     * Get Laravel applications of the given hosting, keyed by their relative path (web, sub/api...).
     *
     * @param  string  $domain
     * @return array
     */
    public function getApps(string $domain): array
    {
        $webPath = $this->getWebPath($domain);

        $paths = array_merge([$webPath.'/web'], glob($webPath.'/sub/*', GLOB_ONLYDIR) ?: []);

        $apps = [];

        foreach ($paths as $path) {
            if (file_exists($path.'/artisan')) {
                $apps[substr($path, strlen($webPath) + 1)] = $path;
            }
        }

        return $apps;
    }

    /**
     * Get the host name which serves the given application.
     *
     * @param  string  $domain
     * @param  string  $app
     * @return string
     */
    public function getAppHost(string $domain, string $app): string
    {
        $domain = $this->toUserFormat($domain);

        if (str_starts_with($app, 'sub/')) {
            return substr($app, 4).'.'.$domain;
        }

        return 'www.'.$domain;
    }

    /**
     * Get the supervisor program name of the given application.
     *
     * @param  string  $domain
     * @param  string  $app
     * @param  string  $type
     * @return string
     */
    public function getProgramName(string $domain, string $app, string $type): string
    {
        return $this->toUserFormat($domain).'-'.str_replace('/', '-', $app).'-'.$type;
    }

    /**
     * Get the PHP version used by the hosting.
     *
     * @param  string  $domain
     * @return string|null
     */
    public function getPHPVersion(string $domain): ?string
    {
        // Use the version of FPM socket from NGINX configuration first
        if ($this->nginx()->exists($domain)) {
            $conf = file_get_contents($this->nginx()->getAvailablePath($domain));

            if (preg_match('/php(\d+\.\d+)-fpm-/', $conf, $matches) && $this->php()->poolExists($domain, $matches[1])) {
                return $matches[1];
            }
        }

        foreach (array_reverse($this->php()->getVersions()) as $version) {
            if ($this->php()->poolExists($domain, $version)) {
                return $version;
            }
        }

        return null;
    }

    /**
     * Get missing requirements of RoadRunner Octane server in the given application.
     *
     * @param  string  $path
     * @return array
     */
    public function getMissingOctaneRequirements(string $path): array
    {
        $lock = file_exists($path.'/composer.lock') ? file_get_contents($path.'/composer.lock') : '';

        $missing = [];

        foreach (['laravel/octane', 'spiral/roadrunner-http', 'spiral/roadrunner-cli'] as $package) {
            if (! str_contains($lock, '"name": "'.$package.'"')) {
                $missing[] = 'composer package <comment>'.$package.'</comment>';
            }
        }

        if (! file_exists($path.'/rr')) {
            $missing[] = 'RoadRunner binary <comment>'.$path.'/rr</comment> (run <comment>php artisan octane:install --server=roadrunner</comment>)';
        }

        return $missing;
    }

    /**
     * Get the value of the variable from the .env file of the application.
     *
     * @param  string  $path
     * @param  string  $key
     * @return string|null
     */
    public function getEnv(string $path, string $key): ?string
    {
        $env = file_exists($path.'/.env') ? file_get_contents($path.'/.env') : '';

        if (! preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $env, $matches)) {
            return null;
        }

        return trim(trim($matches[1]), '"\'') ?: null;
    }

    /**
     * Set variables in the .env file of the application.
     *
     * @param  string  $path
     * @param  array  $values
     * @return bool
     */
    public function setEnv(string $path, array $values): bool
    {
        $env = file_exists($path.'/.env') ? file_get_contents($path.'/.env') : '';

        foreach ($values as $key => $value) {
            $line = $key.'='.$value;

            if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $env)) {
                $env = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $env);
            } else {
                $env = rtrim($env)."\n".$line."\n";
            }
        }

        return file_put_contents($path.'/.env', $env) !== false;
    }

    /**
     * Get the Octane port of the application from its .env file.
     *
     * @param  string  $path
     * @return int|null
     */
    public function getOctanePort(string $path): ?int
    {
        $port = $this->getEnv($path, 'OCTANE_PORT');

        return is_numeric($port) ? (int) $port : null;
    }

    /**
     * Get Octane ports used by all applications on the server, keyed by the application path.
     *
     * @return array
     */
    public function getUsedOctanePorts(): array
    {
        $ports = [];

        $www = $this->config('www_path');

        foreach (array_merge(glob($www.'/*/data/web') ?: [], glob($www.'/*/data/sub/*') ?: []) as $path) {
            if ($port = $this->getOctanePort($path)) {
                $ports[$path] = $port;
            }
        }

        return $ports;
    }

    /**
     * Get a free port for a new Octane server.
     *
     * @return int
     */
    public function getFreeOctanePort(): int
    {
        $used = $this->getUsedOctanePorts();

        $port = $this->octaneFirstPort;

        // RoadRunner RPC listens on port - 1999, so it must be free too
        while (in_array($port, $used) || $this->isPortListening($port) || $this->isPortListening($port - 1999)) {
            $port++;
        }

        return $port;
    }

    /**
     * Determine if something is listening on the given local port.
     *
     * @param  int  $port
     * @return bool
     */
    public function isPortListening(int $port): bool
    {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * Proxy requests of the given application from NGINX to the Octane server.
     *
     * @param  string  $domain
     * @param  string  $app
     * @param  string  $program
     * @param  int  $port
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function enableOctaneNginx(string $domain, string $app, string $program, int $port): Response
    {
        if (! $this->nginx()->exists($domain)) {
            return $this->response()->error('NGINX host <comment>'.$domain.'</comment> does not exist.');
        }

        $confPath = $this->nginx()->getAvailablePath($domain);
        $conf = $this->removeOctaneFromConf(file_get_contents($confPath), $program);
        $root = 'root '.$this->getApps($domain)[$app].'/public;';

        // Subdomains share one regex host, so the application needs its own host
        if (! str_contains($conf, $root)) {
            $conf .= "\n\n".$this->getOctaneSubdomainHost($domain, $app, $program, $conf);
        }

        $location = (string) $this->getStub('nginx.octane.conf')
            ->replace('{program}', $program)
            ->replace('{port}', $port);

        $conf = $this->mapServerSections($conf, function ($section) use ($root, $location) {
            if (! str_contains($section, $root)) {
                return $section;
            }

            $section = str_replace('try_files $uri $uri/ /index.php?$query_string;', 'try_files $uri @octane;', $section);

            return str_replace('    include vpsmanager/general.conf;', rtrim($location)."\n\n".'    include vpsmanager/general.conf;', $section);
        });

        return $this->saveNginxConf($confPath, $conf, 'NGINX host <comment>'.$this->getAppHost($domain, $app).'</comment> has been successfully proxied to Octane server.');
    }

    /**
     * Serve the given application with PHP-FPM again.
     *
     * @param  string  $domain
     * @param  string  $program
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function disableOctaneNginx(string $domain, string $program): Response
    {
        if (! $this->nginx()->exists($domain)) {
            return $this->response()->error('NGINX host <comment>'.$domain.'</comment> does not exist.');
        }

        $confPath = $this->nginx()->getAvailablePath($domain);
        $original = file_get_contents($confPath);
        $conf = $this->removeOctaneFromConf($original, $program);

        if ($conf === $original) {
            return $this->response()->success('NGINX host does not proxy to Octane server.');
        }

        return $this->saveNginxConf($confPath, $conf, 'NGINX host has been successfully switched back to PHP-FPM.');
    }

    /**
     * Remove Octane proxy of the given program from the NGINX configuration.
     *
     * @param  string  $conf
     * @param  string  $program
     * @return string
     */
    protected function removeOctaneFromConf(string $conf, string $program): string
    {
        // Remove the whole host generated only for the Octane server
        $conf = preg_replace('#\n*\# Octane host \('.preg_quote($program, '#').'\)\nserver\s?\{[\s\S]*?\n\}#', '', $conf);

        return $this->mapServerSections($conf, function ($section) use ($program) {
            $marker = '# Octane start ('.$program.')';

            if (! str_contains($section, $marker)) {
                return $section;
            }

            $section = preg_replace('#\n*    '.preg_quote($marker, '#').'[\s\S]*?\# Octane end \('.preg_quote($program, '#').'\)#', '', $section);

            return str_replace('try_files $uri @octane;', 'try_files $uri $uri/ /index.php?$query_string;', $section);
        });
    }

    /**
     * Get a standalone NGINX host of the subdomain application.
     *
     * @param  string  $domain
     * @param  string  $app
     * @param  string  $program
     * @param  string  $conf
     * @return string
     */
    protected function getOctaneSubdomainHost(string $domain, string $app, string $program, string $conf): string
    {
        // Use existing FPM socket for php files which are not served by Octane
        preg_match('/php\d+\.\d+-fpm-[^\s;]+(?=\.sock)/', $conf, $matches);

        return (string) $this->getStub('nginx.template.conf')
            ->addLineBefore('# Octane host ('.$program.')')
            ->replace('{host}', $this->getAppHost($domain, $app))
            ->replace('{path}', $this->getApps($domain)[$app].'/public')
            ->replace('{error_log_path}', $this->nginx()->getErrorLogPath($domain))
            ->replace('{php_sock_name}', $matches[0] ?? $this->php()->getSocketName($domain, $this->getPHPVersion($domain)));
    }

    /**
     * Apply the callback on each server {} section of the NGINX configuration.
     *
     * @param  string  $conf
     * @param  callable  $callback
     * @return string
     */
    protected function mapServerSections(string $conf, callable $callback): string
    {
        return preg_replace_callback('#(?<=^|\n)server\s?\{[\s\S]*?\n\}#', fn ($matches) => $callback($matches[0]), $conf);
    }

    /**
     * Save the NGINX configuration and reload NGINX, or restore the previous one when it is not valid.
     *
     * @param  string  $confPath
     * @param  string  $conf
     * @param  string  $message
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    protected function saveNginxConf(string $confPath, string $conf, string $message): Response
    {
        $backup = file_get_contents($confPath);

        file_put_contents($confPath, $conf);

        if (! $this->nginx()->test()) {
            file_put_contents($confPath, $backup);

            return $this->response()->error('Updated NGINX configuration is not valid, so previous configuration has been restored.');
        }

        if (! $this->nginx()->restart(false)) {
            return $this->response()->error('NGINX configuration has been updated, but NGINX could not be restarted.');
        }

        return $this->response()->success($message);
    }
}
