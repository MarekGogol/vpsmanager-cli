<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class Hosting extends Application
{
    /**
     * Check if nginx host, linux user or php pool exists before creating a hosting.
     *
     * @param  string  $domain
     * @param  array  $config
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function checkErrorsBeforeCreate(string $domain, array $config): Response
    {
        $user = $this->toUserFormat($domain);

        if (! isValidDomain($domain)) {
            return $this->response()->wrongDomainName();
        }

        // Check if we can continue with existing nginx host
        if ($this->nginx()->exists($domain) && ! $this->canContinueNginx($user)) {
            return $this->response()->error('NGINX configuration for domain '.$user.' already exists.');
        }

        // Check if we can continue with existing user
        if ($this->server()->existsUser($user) && ! $this->canContinueUser($user)) {
            return $this->response()->error('Linux user '.$user.' already exists.');
        }

        if (! $this->php()->isInstalled($config['php_version'])) {
            return $this->response()->error('PHP version '.$config['php_version'].' is not installed.');
        }

        // Check if we can continue with existing PHP pool configuration
        if ($this->php()->poolExists($domain, $config['php_version']) && ! $this->canContinuePool($user, $config['php_version'])) {
            return $this->response()->error('PHP pool '.$user.'.conf for PHP version '.$config['php_version'].' already exists.');
        }

        return $this->response();
    }

    /**
     * Ask a confirmation question when the console is available.
     *
     * @param  string  $message
     * @return bool
     */
    private function confirm(string $message): bool
    {
        $m = vpsManager();

        // If console is not booted properly
        if (! ($m->output && $m->input && $m->helper)) {
            return false;
        }

        return (bool) $m->helper->ask($m->input, $m->output, new ConfirmationQuestion($message, false));
    }

    /**
     * Check if we can continue with existing user.
     *
     * @param  string  $user
     * @return bool
     */
    private function canContinueUser(string $user): bool
    {
        return $this->confirm(
            "\n".'<error>User '.$user.' already exists.</error>'."\n".'Would you like to continue with the existing user? (y/N) ',
        );
    }

    /**
     * Check if we can continue with existing nginx configuration.
     *
     * @param  string  $user
     * @return bool
     */
    private function canContinueNginx(string $user): bool
    {
        return $this->confirm(
            "\n".'<error>Webhosting '.$user.' already exists and has NGINX configuration.</error>'."\n".
            'Would you like to use the existing <comment>'.$this->nginx()->getAvailablePath($user).'</comment> configuration? (y/N) ',
        );
    }

    /**
     * Check if we can continue with existing php pool.
     *
     * @param  string  $user
     * @param  string  $php_version
     * @return bool
     */
    private function canContinuePool(string $user, string $php_version): bool
    {
        return $this->confirm(
            "\n".'<error>PHP '.$php_version.' pool for domain '.$user.' already exists.</error>'."\n".
            'Would you like to use the existing <comment>'.$this->php()->getPoolPath($user, $php_version).'</comment> configuration? (y/N) ',
        );
    }

    /**
     * Get value from hosting configuration.
     *
     * @param  array  $config
     * @param  string  $key
     * @param  mixed  $default
     * @return mixed
     */
    private function getParam(array $config, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $config)) {
            return $config[$key];
        }

        return $default;
    }

    /**
     * Create new hosting.
     *
     * @param  string  $domain
     * @param  array  $config
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function create(string $domain, array $config = []): Response
    {
        $config['php_version'] = $this->getParam($config, 'php_version', $this->config('php_version'));

        // Check errors
        if (($response = $this->checkErrorsBeforeCreate($domain, $config))->isError()) {
            return $response;
        }

        // Create user
        if (($response = $this->server()->createUser($domain, $config)->writeln(true))->isError()) {
            return $response;
        }

        // Create mysql database
        if (! empty($config['database']) && ($response = $this->mysql()->createDatabase($domain)->writeln(true))->isError()) {
            return $response;
        }

        // Create domain directory tree
        if (($response = $this->server()->createDomainTree($domain, $config)->writeln())->isError()) {
            return $response;
        }

        // Create chroot directory tree
        if (! empty($config['chroot']) && ($response = $this->chroot()->create($domain, $config)->writeln())->isError()) {
            return $response;
        }

        // Create php pool
        if (($response = $this->php()->createPool($domain, $config)->writeln())->isError()) {
            return $response;
        }

        // Create nginx host
        if (($response = $this->nginx()->createHost($domain, $config)->writeln())->isError()) {
            return $response;
        }

        // Test and reboot services
        $this->rebootNginx();
        $this->rebootPHP($config['php_version']);

        return $this->response()->success("\n".'Hosting has been successfully created!');
    }

    /**
     * Test nginx configuration and restart nginx service.
     *
     * @return void
     */
    public function rebootNginx(): void
    {
        if (! $this->nginx()->test()) {
            $this->response()
                ->message('<error>NGINX configuration is not valid, so the service could not be restarted.</error>')
                ->writeln();

            return;
        }

        if ($this->nginx()->restart(false)) {
            $this->response()
                ->success('<comment>NGINX has been successfully restarted.</comment>')
                ->writeln();
        } else {
            $this->response()
                ->message('<error>An error occurred while restarting NGINX. Please restart the service manually.</error>')
                ->writeln();
        }
    }

    /**
     * Restart php-fpm service of given version.
     *
     * @param  string  $php_version
     * @return void
     */
    public function rebootPHP(string $php_version): void
    {
        if ($this->php()->restart($php_version)) {
            $this->response()
                ->success('<comment>PHP '.$php_version.' FPM has been successfully restarted.</comment>')
                ->writeln();
        } else {
            $this->response()
                ->error('<error>An error occurred while restarting PHP '.$php_version.' FPM. Please restart the service manually.</error>')
                ->writeln(null, true);
        }
    }

    /**
     * Remove hosting.
     *
     * @param  string  $domain
     * @param  bool  $remove_data
     * @param  bool  $remove_mysql
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function remove(string $domain, bool $remove_data = false, bool $remove_mysql = false): Response
    {
        // Remove nginx host
        if ($this->nginx()->removeHost($domain)) {
            $this->response()
                ->success('<comment>NGINX</comment> <info>host has been successfully disabled and removed.</info>')
                ->writeln();
        } else {
            $this->response()
                ->error('<error>NGINX host could not be deleted.</error>')
                ->writeln(null, true);
        }

        // PHP-FPM versions which should be restarted
        $reboot_php_versions = [];

        // Remove pools from all php versions
        foreach ($this->php()->getVersions() as $php_version) {
            if (! $this->php()->poolExists($domain, $php_version)) {
                continue;
            }

            if ($this->php()->removePool($domain, $php_version)) {
                $reboot_php_versions[] = $php_version;

                $this->response()
                    ->success('<comment>PHP '.$php_version.'</comment> <info>pool has been successfully removed.</info>')
                    ->writeln();
            } else {
                $this->response()
                    ->message('<error>PHP '.$php_version.' pool could not be deleted.</error>')
                    ->writeln();
            }
        }

        // Test and reboot services
        $this->rebootNginx();

        // Reboot all php versions from which a pool has been removed
        foreach ($reboot_php_versions as $version) {
            $this->rebootPHP($version);
        }

        // Remove user
        if ($this->server()->deleteUser($domain)) {
            $this->response()
                ->success('<info>User</info> <comment>'.$domain.'</comment> <info>has been successfully removed.</info>')
                ->writeln();
        } else {
            $this->response()
                ->message('<error>User '.$domain.' could not be deleted.</error>')
                ->writeln();
        }

        // Remove mysql data
        if ($remove_mysql === true) {
            $this->mysql()
                ->removeDatabaseWithUser($domain)
                ->writeln(null, true);
        }

        // Remove storage data
        if ($remove_data === true) {
            $userDirPath = $this->getUserDirPath($domain);

            if ($this->server()->deleteDomainTree($domain)) {
                $this->response()
                    ->success('<info>Data storage</info> <comment>'.$userDirPath.'</comment> <info>has been deleted.</info>')
                    ->writeln();
            } else {
                $this->response()
                    ->message('<error>Data storage '.$userDirPath.' could not be deleted.</error>')
                    ->writeln();
            }
        }

        return $this->response()->success('<info>Hosting has been successfully removed.</info>');
    }
}
