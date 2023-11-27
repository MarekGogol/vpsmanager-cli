<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

class Server extends Application
{
    /*
     * Check if user exists
     */
    public function existsUser($user)
    {
        return shell_exec('getent passwd ' . $this->toUserFormat($user)) ? true : false;
    }

    public function getHostingUserGroup()
    {
        return 'vpsmanager_hosting_user';
    }

    /*
     * Create linux user
     */
    public function createUser(string $user, $config = [])
    {
        //Check if is user in valid format
        if (!isValidDomain($user)) {
            return $this->response()->wrongDomainName();
        }

        $user = $this->toUserFormat($user);

        //Check if user exists
        if ($this->existsUser($user)) {
            return $this->response();
        }

        //Web path
        $web_path = $this->getWebPath($user, $config);

        $password = getRandomPassword(16);

        $home_dir = isset($config['chroot']) && $config['chroot'] === true ? vpsManager()->getWebDirectory() : $web_path;

        $this->createGroupIfNotExists($this->getHostingUserGroup());

        //Create new linux user
        exec('useradd -s /bin/bash -d ' . $home_dir . ' -U ' . $user . ' -G ' . $this->getHostingUserGroup() . ' -p $(openssl passwd -1 ' . $password . ')', $output, $return_var);

        if ($return_var != 0) {
            return $this->response()->error('User could not be created.');
        }

        return $this->response()->success(
            '<info>Linux user has been successfully created.</info>' . "\n" . 'User: <comment>' . $user . '</comment>' . "\n" . 'Password: <comment>' . $password . '</comment>',
        );
    }

    public function createGroupIfNotExists($group)
    {
        exec('(getent group ' . $group . ' || groupadd ' . $group . ') 2> /dev/null');
    }

    public function changeHomeDir($user, $dir)
    {
        exec('usermod -d ' . $dir . ' ' . $user . ' 2> /dev/null');
    }

    /*
     * Delete user
     */
    public function deleteUser(string $user)
    {
        //Check if is user in valid format
        if (!isValidDomain($user)) {
            return false;
        }

        $user = $this->toUserFormat($user);

        if (!$this->existsUser($user)) {
            return true;
        }

        //Create new linux user
        exec('userdel ' . $user, $output, $return_var);

        return $return_var == 0 ? true : false;
    }

    /*
     * Return if domain directory exists
     */
    public function existsDomainTree($domain, $config = null)
    {
        if (isset($config['www_path'])) {
            return false;
        }

        return file_exists($this->getUserDirPath($domain, $config));
    }

    /*
     * Create directory tree for domain
     */
    public function createDomainTree($domain, $config = null)
    {
        $user = $this->toUserFormat($domain);

        if (!isValidDomain($domain)) {
            return $this->response()->wrongDomainName();
        }

        $userDir = $this->getUserDirPath($domain, $config);
        $web_path = $this->getWebPath($domain, $config);

        $paths = [];

        //Add domain root folder for chroot
        if (isset($config['chroot']) && $config['chroot'] === true) {
            $paths[$userDir] = ['chmod' => 755, 'user' => 'root', 'group' => 'root'];
        } else {
            $paths[$userDir] = 710;
        }

        $paths = array_merge($paths, [
            $web_path => 710,
            $web_path . '/web' => 710,
            $web_path . '/web/public' => 710,
            $web_path . '/sub' => 710,
            $web_path . '/logs' => ['chmod' => 750, 'user' => 'root', 'group' => $user],
            $web_path . '/.ssh' => ['chmod' => 710, 'user' => $user, 'group' => $user],
        ]);

        //Create subdomain
        if ($sub = $this->getSubdomain($domain)) {
            $paths[$web_path . '/sub/' . $sub] = 710;
            $paths[$web_path . '/sub/' . $sub . '/public'] = 710;
        }

        //If path has been given
        if (isset($config['www_path'])) {
            $paths = [$config['www_path'] => 710];
        }

        createDirectories($paths, $user, $config, function ($path, $permissions) use ($domain) {
            //For public directory, copy index
            if (substr($path, -7) == '/public') {
                $this->getStub('hello.php')
                    ->replace('{user}', $domain)
                    ->save($path . '/index.php');
            }
        });

        return $this->response()->success('Directory <info>' . $web_path . '</info> has been successfully setted up.');
    }

    /*
     * Remove domain tree
     */
    public function deleteDomainTree($domain)
    {
        if (!isValidDomain($domain)) {
            return false;
        }

        $web_path = vpsManager()->getUserDirPath($domain);

        //If is has chroot, unmount mounted directories
        vpsManager()
            ->chroot()
            ->remove($domain)
            ->writeln();

        return system('rm -rf ' . $web_path) == 0;
    }

    /*
     * Check if linux extension is installed
     */
    public function isInstalledExtension($apt)
    {
        exec('dpkg -s ' . $apt . ' 2>&1', $output, $return_var);

        return $return_var == 0;
    }
}

?>
