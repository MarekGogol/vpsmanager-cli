<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

class Chroot extends Application
{
    /*
     * Create directory tree for working chroot
     */
    public function createChrootTree($domain, $config = null)
    {
        $user = $this->toUserFormat($domain);

        if ( ! isValidDomain($domain) )
            return $this->response()->wrongDomainName();

        $web_path = $this->getWebRootPath($domain, $config);

        //Clone bashrc
        // exec('cp -Rf '.__DIR__.'/../Resources/user/.bash_profile '.$web_path.'/data');

        createDirectories([
            $web_path.'/tmp' => ['user' => $domain, 'group' => $domain, 'chmod' => 700],
            $web_path.'/dev/null' => ['mknod' => [666, 'c 1 3']],
            $web_path.'/dev/tty' => ['mknod' => [666, 'c 5 0']],
            $web_path.'/dev/random' => ['mknod' => [444, 'c 1 8']],
            $web_path.'/dev/urandom' => ['mknod' => [444, 'c 1 1']],
        ], $user);

        //Allow commands
        $this->addChrootExtension($web_path, '/bin/bash', true);
        $this->addChrootExtension($web_path, '/bin/sh', true);
        $this->addChrootExtension($web_path, '/bin/dash', true);
        $this->addChrootExtension($web_path, '/bin/ls', true);
        $this->addChrootExtension($web_path, '/bin/rm', true);
        $this->addChrootExtension($web_path, '/bin/cp', true);
        $this->addChrootExtension($web_path, '/bin/mkdir', true);
        $this->addChrootExtension($web_path, '/bin/chown', true);
        $this->addChrootExtension($web_path, '/bin/chmod', true);
        $this->addChrootExtension($web_path, '/bin/cat', true);
        $this->addChrootExtension($web_path, '/bin/nano', true);
        $this->addChrootExtension($web_path, '/usr/bin/id', true);
        $this->addChrootExtension($web_path, '/usr/bin/groups', true);
        $this->addChrootExtension($web_path, '/usr/bin/wget', true);
        $this->addChrootExtension($web_path, '/usr/bin/openssl', true);
        $this->addChrootExtension($web_path, '/usr/share/openssh');

        //Set up clear command and terminal info
        $this->addChrootExtension($web_path, '/lib/terminfo');
        $this->addChrootExtension($web_path, '/usr/bin/clear', true);

        $this->addChrootExtension($web_path, '/usr/bin/ssh', true);
        $this->addChrootExtension($web_path, '/usr/bin/ssh-keygen', true);
        $this->addChrootExtension($web_path, '/etc/ssl/certs/ca-certificates.crt');

        //Gix groups names and user names support
        $this->fixGroupNames($user, $web_path);

        //Fix dns resolving, also required for proper git working
        $this->fixDNSResolving($web_path);

        //Add git command
        $this->fixGitSupport($web_path);

        //Cerificates for ssl
        $this->addChrootExtension($web_path, '/usr/share/ca-certificates');

        //Allow timezone (for composer support etc..)
        $this->addChrootExtension($web_path, '/usr/share/zoneinfo');

        $this->addPhpChrootSupport($web_path);

        //Allow composer
        $this->addComposerSupport($web_path);

        return $this->response()->success('Chroot for directory <info>'.$web_path.'</info> has been successfully setted up.');
    }

    public function addComposerSupport($web_path)
    {
        $this->addChrootExtension($web_path, '/usr/bin/composer', true);
        $this->addChrootExtension($web_path, '/usr/share/doc/composer');
    }

    public function fixGroupNames($user, $web_path)
    {
        $this->addChrootExtension($web_path, '/lib/x86_64-linux-gnu/libnss_files.so.2');

        $allowGroupNames = [
            'root',
            'www-data',
            $user,
            $this->server()->getHostingUserGroup(),
        ];

        exec('cat /etc/group | grep "'.implode('\|', $allowGroupNames).'" >> '.$web_path.'/etc/group', $output);
        exec('cat /etc/passwd | grep "'.implode('\|', $allowGroupNames).'" >> '.$web_path.'/etc/passwd', $output);
    }

    public function fixGitSupport($web_path)
    {
        $this->addChrootExtension($web_path, '/usr/bin/git', true);
        $this->addChrootExtension($web_path, '/usr/share/git-core');
        $this->addChrootExtension($web_path, '/usr/lib/git-core');

        //Allow https for git clone
        $this->addChrootExtension($web_path, '/usr/lib/git-core/git-remote-https', true);

        //Install git extensions
        // foreach (array_slice(scandir($gitStoreExt = '/usr/lib/git-core'), 2) as $extension) {
        //     $this->addChrootExtension($web_path, $gitStoreExt.'/'.$extension, true);
        // }
    }

    public function fixDNSResolving($web_path)
    {
        $this->addChrootExtension($web_path, '/lib/x86_64-linux-gnu/libnss_dns.so.2', true);
        $this->addChrootExtension($web_path, '/etc/resolv.conf', true);
    }

    public function addPhpChrootSupport($web_path)
    {
        //Allow all php versions
        $this->addChrootExtension($web_path, '/usr/bin/php', true);
        $this->addChrootExtension($web_path, '/usr/share/php');

        //Allow all php aliases on system
        foreach ($this->php()->getVersions() as $phpVersion) {
            $this->addChrootExtension($web_path, '/etc/php/'.$phpVersion.'/cli');
            $this->addChrootExtension($web_path, '/etc/php/'.$phpVersion.'/mods-available');
            $this->addChrootExtension($web_path, '/usr/bin/php'.$phpVersion, true);
        }

        //Install all php extensions dependencies
        foreach (array_slice(scandir($phpExtPath = '/usr/lib/php/20180731'), 2) as $extension) {
            $this->addChrootExtension($web_path, $phpExtPath.'/'.$extension, true);
        }

        //Allow primary php alias
        $this->addChrootExtension($web_path, '/etc/alternatives/php', true);
    }

    /*
     * Copy linux extension
     */
    public function addChrootExtension($web_path, $extension, $withDependencies = false)
    {
        createParentDirectory($web_path.'/'.$extension);

        //Copy extension
        exec('cp -rL '.$extension.' '.$web_path.'/'.$extension, $output);

        if ( $withDependencies === true )
        {
            exec('ldd "'.$extension.'" | grep -o \'\(\/.*\s\)\'', $dependencies);

            foreach ($dependencies as $dependency) {
                createParentDirectory($web_path.$dependency);

                exec('cp -rL '.$dependency.' '.$web_path.$dependency);
            }
        }
    }
}

?>