<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

class Server extends Application
{
    /**
     * Determine if the linux user exists.
     *
     * @param  string  $user
     * @return bool
     */
    public function existsUser($user): bool
    {
        return (bool) shell_exec('getent passwd '.$this->toUserFormat($user));
    }

    /**
     * Get the linux group of all hosting users.
     *
     * @return string
     */
    public function getHostingUserGroup(): string
    {
        return 'vpsmanager_hosting_user';
    }

    /**
     * Create a new linux user.
     *
     * @param  string  $user
     * @param  array  $config
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function createUser(string $user, $config = []): Response
    {
        // Check if user is in valid format
        if (! isValidDomain($user)) {
            return $this->response()->wrongDomainName();
        }

        $user = $this->toUserFormat($user);

        // Check if user exists
        if ($this->existsUser($user)) {
            return $this->response();
        }

        $web_path = $this->getWebPath($user, $config);

        $password = getRandomPassword(16);

        $home_dir = isset($config['chroot']) && $config['chroot'] === true ? $this->getWebDirectory() : $web_path;

        $this->createGroupIfNotExists($this->getHostingUserGroup());

        // Create new linux user
        exec('useradd -s /bin/bash -d '.$home_dir.' -U '.$user.' -G '.$this->getHostingUserGroup().' -p $(openssl passwd -1 '.escapeshellarg($password).')', $output, $return_var);

        if ($return_var != 0) {
            return $this->response()->error('User could not be created.');
        }

        return $this->response()->success(
            '<info>Linux user has been successfully created.</info>'."\n".'User: <comment>'.$user.'</comment>'."\n".'Password: <comment>'.$password.'</comment>',
        );
    }

    /**
     * Create the linux group if it does not exist.
     *
     * @param  string  $group
     * @return void
     */
    public function createGroupIfNotExists($group): void
    {
        exec('(getent group '.$group.' || groupadd '.$group.') 2> /dev/null');
    }

    /**
     * Change the home directory of the linux user.
     *
     * @param  string  $user
     * @param  string  $dir
     * @return void
     */
    public function changeHomeDir($user, $dir): void
    {
        exec('usermod -d '.$dir.' '.$user.' 2> /dev/null');
    }

    /**
     * Delete the linux user.
     *
     * @param  string  $user
     * @return bool
     */
    public function deleteUser(string $user): bool
    {
        // Check if user is in valid format
        if (! isValidDomain($user)) {
            return false;
        }

        $user = $this->toUserFormat($user);

        if (! $this->existsUser($user)) {
            return true;
        }

        // Delete linux user
        exec('userdel '.$user, $output, $return_var);

        return $return_var == 0;
    }

    /**
     * Determine if the domain directory exists.
     *
     * @param  string  $domain
     * @param  array|null  $config
     * @return bool
     */
    public function existsDomainTree($domain, $config = null): bool
    {
        if (isset($config['www_path'])) {
            return false;
        }

        return file_exists($this->getUserDirPath($domain, $config));
    }

    /**
     * Create the directory tree for the domain.
     *
     * @param  string  $domain
     * @param  array|null  $config
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function createDomainTree($domain, $config = null): Response
    {
        if (! isValidDomain($domain)) {
            return $this->response()->wrongDomainName();
        }

        $user = $this->toUserFormat($domain);

        $userDir = $this->getUserDirPath($domain, $config);
        $web_path = $this->getWebPath($domain, $config);

        $paths = [];

        // Add domain root folder for chroot
        if (isset($config['chroot']) && $config['chroot'] === true) {
            $paths[$userDir] = ['chmod' => 755, 'user' => 'root', 'group' => 'root'];
        } else {
            $paths[$userDir] = 710;
        }

        $paths = array_merge($paths, [
            $web_path => 710,
            $web_path.'/web' => 710,
            $web_path.'/web/public' => 710,
            $web_path.'/sub' => 710,
            $web_path.'/logs' => ['chmod' => 750, 'user' => 'root', 'group' => $user],
            $web_path.'/.ssh' => ['chmod' => 710, 'user' => $user, 'group' => $user],
        ]);

        // Create subdomain
        if ($sub = $this->getSubdomain($domain)) {
            $paths[$web_path.'/sub/'.$sub] = 710;
            $paths[$web_path.'/sub/'.$sub.'/public'] = 710;
        }

        // Custom path has been given
        if (isset($config['www_path'])) {
            $paths = [$config['www_path'] => 710];
        }

        createDirectories($paths, $user, $config ?: [], function ($path) use ($domain) {
            // Copy index file into the public directory
            if (str_ends_with($path, '/public')) {
                $this->getStub('hello.php')
                    ->replace('{user}', $domain)
                    ->save($path.'/index.php');
            }
        });

        return $this->response()->success('Directory <info>'.$web_path.'</info> has been successfully set up.');
    }

    /**
     * Delete the domain directory tree.
     *
     * @param  string  $domain
     * @return bool
     */
    public function deleteDomainTree($domain): bool
    {
        if (! isValidDomain($domain)) {
            return false;
        }

        $web_path = vpsManager()->getUserDirPath($domain);

        // Unmount mounted directories if the domain has chroot
        vpsManager()
            ->chroot()
            ->remove($domain)
            ->writeln();

        $result = 0;

        system('rm -rf '.escapeshellarg($web_path), $result);

        return $result == 0;
    }

    /**
     * Determine if the apt package is installed.
     *
     * @param  string  $apt
     * @return bool
     */
    public function isInstalledExtension($apt): bool
    {
        exec('dpkg -s '.$apt.' 2>&1', $output, $return_var);

        return $return_var == 0;
    }
}
