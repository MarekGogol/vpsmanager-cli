<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;
use Gogol\VpsManagerCLI\Helpers\Stub;

class Chroot extends Application
{
    protected $chrootGroup = 'vpsmanager_chroot_user';

    /*
     * Create directory tree for working chroot
     */
    public function create($domain, $config = null, $move_web_data = false)
    {
        $user = $this->toUserFormat($domain);

        if ( ! isValidDomain($domain) )
            return $this->response()->wrongDomainName();

        $userDir = $this->getUserDirPath($domain, $config);
        $web_path = $this->getWebPath($domain, $config);

        $this->response()->success('Setting chroot directory for <info>'.$userDir.'</info>')->writeln();

        //Set chroot permissions of root directory
        exec('chown root:root '.$userDir.' && chmod 755 '.$userDir);

        $this->server()->createGroupIfNotExists($this->chrootGroup);

        //Add chroot group for specific user
        exec('usermod -a -G '.$this->chrootGroup.' '.$user.' 2> /dev/null');

        //Change user homedir directory
        $this->server()->changeHomeDir($user, '/data');

        $this->disableLoginMessage($web_path);

        //Move all old web data into user/data folder
        //Dont move files when data directory exists already
        if ( $move_web_data === true && ! file_exists($web_path) ) {
            $webDir = trim($this->getWebDirectory(), '/');

            createDirectories([
                $web_path => 710,
            ], $user);

            exec('cd '.$userDir.'; find . -maxdepth 1 ! -name '.$webDir.' ! -name . -exec mv "{}" '.$webDir.' \;');

            $this->response()->success('All web data moved from <info>'.$userDir.'</info> to <info>'.$userDir.'/'.$webDir.'</info>')->writeln();
        }

        //Clone bashrc
        // exec('cp -Rf '.__DIR__.'/../Resources/user/.bash_profile '.$userDir.'/data');

        createDirectories([
            $userDir.'/tmp' => ['user' => $domain, 'group' => $domain, 'chmod' => 700],
            $userDir.'/proc' => ['user' => 'root', 'group' => 'root', 'chmod' => 710],
            $userDir.'/dev/null' => ['mknod' => [666, 'c 1 3']],
            $userDir.'/dev/tty' => ['mknod' => [666, 'c 5 0']],
            $userDir.'/dev/random' => ['mknod' => [444, 'c 1 8']],
            $userDir.'/dev/urandom' => ['mknod' => [444, 'c 1 9']],
            $userDir.'/usr/include' => ['user' => 'root', 'group' => 'root', 'chmod' => 755], //we need chmood 755, because libpng needs to read files from include
            $userDir.'/usr/lib/x86_64-linux-gnu' => ['user' => 'root', 'group' => 'root', 'chmod' => 755], //we need chmood 755, because libpng needs to read files from include
            $userDir.'/usr/local' => ['user' => 'root', 'group' => 'root', 'chmod' => 777], //we need chmood 755, because libpng needs to read files from include
        ], $user, null, null, false);

        //Allow regularcommands
        $this->addChrootExtension($userDir, '/bin/bash', true);
        $this->addChrootExtension($userDir, '/bin/sh', true);
        $this->addChrootExtension($userDir, '/bin/dash', true);
        $this->addChrootExtension($userDir, '/bin/ls', true);
        $this->addChrootExtension($userDir, '/bin/rm', true);
        $this->addChrootExtension($userDir, '/bin/cp', true);
        $this->addChrootExtension($userDir, '/bin/mkdir', true);
        $this->addChrootExtension($userDir, '/bin/chown', true);
        $this->addChrootExtension($userDir, '/bin/chmod', true);
        $this->addChrootExtension($userDir, '/bin/cat', true);
        $this->addChrootExtension($userDir, '/bin/nano', true);
        $this->addChrootExtension($userDir, '/usr/bin/id', true);
        $this->addChrootExtension($userDir, '/usr/bin/groups', true);
        $this->addChrootExtension($userDir, '/usr/bin/wget', true);
        $this->addChrootExtension($userDir, '/usr/bin/openssl', true);
        $this->addChrootExtension($userDir, '/usr/share/openssh');
        $this->addChrootExtension($userDir, '/usr/bin/whoami', true);

        //Fix username and hostname in terminal after login in chroot env
        $this->addChrootExtension($userDir, '/etc/bash.bashrc');
        $this->addChrootExtension($userDir, '/etc/profile');

        //Nano settings
        $this->addChrootExtension($userDir, '/etc/nanorc');
        $this->addChrootExtension($userDir, '/usr/share/nano');

        //Set up clear command and terminal info
        $this->addChrootExtension($userDir, '/lib/terminfo');
        $this->addChrootExtension($userDir, '/usr/bin/clear', true);

        //Allow ssh command
        $this->addChrootExtension($userDir, '/usr/bin/ssh', true);
        $this->addChrootExtension($userDir, '/usr/bin/ssh-keygen', true);

        //Fix ssl certificates for https,ssh etc...
        // $this->addChrootExtension($userDir, '/etc/ssl/certs/ca-certificates.crt');
        $this->addChrootExtension($userDir, '/etc/ssl/certs');
        $this->addChrootExtension($userDir, '/etc/ca-certificates.conf');
        $this->addChrootExtension($userDir, '/etc/ca-certificates');
        $this->addChrootExtension($userDir, '/usr/share/ca-certificates');
        $this->addChrootExtension($userDir, '/usr/lib/ssl');
        $this->addChrootExtension($userDir, '/usr/share/ca-certificates');

        //Gix groups names and user names support
        $this->fixGroupNames($user, $userDir);

        //Fix dns resolving, also required for proper git working
        $this->fixDNSResolving($userDir);

        //Add git command
        $this->fixGitSupport($userDir);

        //Allow timezone (for composer support etc..)
        $this->addChrootExtension($userDir, '/usr/share/zoneinfo');

        //Allow php support in chroot
        $this->addPhpChrootSupport($userDir);

        //Allow composer support in chroot
        $this->addComposerSupport($userDir);

        //Allow npm + nodejs
        $this->addNodeJs($userDir);

        //Add chroot restriction into sshd_config
        $this->addChrootGroupIntoSSH();

        return $this->response()->success('Chroot for directory <info>'.$userDir.'</info> has been successfully setted up.');
    }

    /**
     * Disable login message
     */
    public function getNologinFile($web_path)
    {
        return $web_path.'/.hushlogin';
    }

    public function disableLoginMessage($web_path)
    {
        $file = $this->getNologinFile($web_path);

        //Creat hushlogin to hide message
        @file_put_contents($file, '');
    }

    /*
     * Add chroot restriction into sshd_config
     */
    public function addChrootGroupIntoSSH(){
        $file = '/etc/ssh/sshd_config';

        $data = file_get_contents($file);

        $section = "\n
Match Group ".$this->chrootGroup."
    ChrootDirectory /var/www/%u
    AuthorizedKeysFile /var/www/%u/data/.ssh/authorized_keys
    Banner /etc/ssh/vpsmanager_banner.txt\n";

        //If section does not exists
        if ( strpos($data, $this->chrootGroup) === false ) {
            //Set default mode as internal-sftp for working both sftp ans ssh
            $data = str_replace("Subsystem\tsftp\t/usr/lib/openssh/sftp-server", "Subsystem\tsftp\tinternal-sftp", $data);

            file_put_contents($file, $data);
            file_put_contents($file, $section, FILE_APPEND);

            //Restart ssh after sshd_config modification
            $this->ssh()->rebootSSH();
        }


        //Add banner if does not exists
        $sysBannerPath = '/etc/ssh/vpsmanager_banner.txt';

        if ( !file_exists($sysBannerPath) ){
            $bannerPath = (new Stub)->getStubPath('banner.txt');

            exec('cp -r '.$bannerPath.' '.$sysBannerPath);
        }
    }

    /*
     * Check if given directory is chroot
     */
    public function isChroot($userDir)
    {
        return file_exists($userDir.'/etc/passwd')
            && file_exists($userDir.'/data')
            && file_exists($userDir.'/lib');
    }

    /*
     * Remove all chroot directories except data
     */
    public function remove($domain)
    {
        $user = $this->toUserFormat($domain);

        if ( ! isValidDomain($domain) )
            return $this->response()->wrongDomainName();

        $userDir = $this->getUserDirPath($domain);
        $webDir = $this->getWebPath($domain);

        //Check if is chroot environment
        if ( ! $this->isChroot($userDir) ) {
            return $this->response()->error('<error>This is not chroot environment.</error>');
        }

        //Unmount directories
        foreach (['proc'] as $dir) {
            if ( file_exists($userDir.'/'.$dir) ) {
                exec('umount '.$userDir.'/'.$dir, $output);
            }
        }

        //Remove all chroot directories
        foreach (['bin', 'dev', 'etc', 'lib', 'lib64', 'proc', 'tmp', 'usr'] as $dir) {
            if ( file_exists($userDir.'/'.$dir) ) {
                exec('rm -rf '.$userDir.'/'.$dir, $output);
            }
        }

        //Set chroot permissions of root directory
        exec('chown '.$user.':www-data '.$userDir.' && chmod 710 '.$userDir);

        //Remove group from user
        exec('deluser '.$user.' '.$this->chrootGroup.' 2> /dev/null', $output);

        //Change user homedir directory
        $this->server()->changeHomeDir($user, $webDir);

        //Remove file that disables login message
        @unlink($this->getNologinFile($webDir));

        return $this->response()->success('Chroot directories has been successfully removed.');
    }

    /**
     * We need add lot of libraries because of npm pngquant-bin library
     * which needs gcc/make and many more...
     * see pngquant-bin/lib/install.js
     */
    public function addNodeJs($userDir)
    {
        //Add nodejs
        $this->addChrootExtension($userDir, '/usr/bin/node', true);

        //Add npm command
        $this->addChrootExtension($userDir, '/usr/lib/node_modules/npm');
        exec('ln -s -f /usr/lib/node_modules/npm/bin/npm-cli.js '.$userDir.'/usr/bin/npm', $output);

        //Add cpp+ libraries support
        $this->addChrootExtension($userDir, '/usr/include');

        //Allow libpng/pnguant support
        $this->addChrootExtension($userDir, '/usr/bin/pngquant');

        //Added required commands for proper npm workflow
        $this->addChrootExtension($userDir, '/usr/bin/env');
        $this->addChrootExtension($userDir, '/usr/bin/ar');
        $this->addChrootExtension($userDir, '/usr/bin/find', true);
        $this->addChrootExtension($userDir, '/bin/uname', true);
        $this->addChrootExtension($userDir, '/bin/grep', true);
        $this->addChrootExtension($userDir, '/usr/bin/install', true);
        $this->addChrootExtension($userDir, '/usr/bin/as', true);
        $this->addChrootExtension($userDir, '/usr/bin/make', true);

        //Allow all required libraries for npm packages
        $this->addChrootExtension($userDir, '/usr/lib/x86_64-linux-gnu/libpng16.a');
        $this->addChrootExtension($userDir, '/lib/x86_64-linux-gnu/ld-linux-x86-64.so.2');
        $this->addChrootExtension($userDir, '/lib/x86_64-linux-gnu/libmvec.so.1');
        $this->addChrootExtension($userDir, '/usr/lib/x86_64-linux-gnu/libz.so');
        $this->addChrootExtension($userDir, '/usr/lib/x86_64-linux-gnu/Scrt1.o');
        $this->addChrootExtension($userDir, '/usr/lib/x86_64-linux-gnu/crti.o');
        $this->addChrootExtension($userDir, '/usr/lib/x86_64-linux-gnu/libisl.so.19');
        $this->addChrootExtension($userDir, '/usr/lib/x86_64-linux-gnu/libmpc.so.3');
        // $this->addChrootExtension($userDir, '/usr/lib/x86_64-linux-gnu/libmpfr.so');
        $this->addChrootExtension($userDir, '/usr/lib/x86_64-linux-gnu/crtn.o');
        $this->addChrootExtension($userDir, '/usr/lib/x86_64-linux-gnu/libc.so');
        $this->addChrootExtension($userDir, '/usr/lib/x86_64-linux-gnu/libc_nonshared.a');
        $this->addChrootExtension($userDir, '/usr/lib/x86_64-linux-gnu/libm.a');
        $this->addChrootExtension($userDir, '/usr/lib/x86_64-linux-gnu/libm-2.27.a');
        $this->addChrootExtension($userDir, '/usr/lib/x86_64-linux-gnu/libm.so');
        $this->addChrootExtension($userDir, '/usr/lib/x86_64-linux-gnu/libmvec.so');
        $this->addChrootExtension($userDir, '/usr/lib/x86_64-linux-gnu/libmvec.a');
        $this->addChrootExtension($userDir, '/usr/lib/x86_64-linux-gnu/libmvec_nonshared.a');

        //Allow gcc
        $this->allowGcc($userDir);

        //If proc is not mounted
        if ( ! file_exists($userDir.'/proc/cpuinfo') ) {
            exec('mount --bind /proc '.$userDir.'/proc', $output);
        }
    }

    public function allowGcc($userDir)
    {
        $this->addChrootExtension($userDir, '/usr/bin/gcc', true);
        $this->addChrootExtension($userDir, '/usr/bin/gcc-7', true);
        $this->addChrootExtension($userDir, '/usr/lib/gcc');
        $this->addChrootExtension($userDir, '/usr/lib/gcc/x86_64-linux-gnu/7.4.0/cc1', true);
        $this->addChrootExtension($userDir, '/usr/lib/gcc/x86_64-linux-gnu/7.4.0/collect2', true);

        //Allow ldd
        $this->addChrootExtension($userDir, '/usr/bin/ld', true);
        $this->addChrootExtension($userDir, '/usr/bin/ld.gold', true);
        $this->addChrootExtension($userDir, '/usr/bin/ld.bfd', true);

        // $gcc = glob('/usr/lib/gcc/x86_64-linux-gnu/**/**/**');

        // foreach ($gcc as $path) {
        //     $this->addChrootExtension($userDir, $path, true);
        // }
    }

    public function addComposerSupport($userDir)
    {
        $this->addChrootExtension($userDir, '/usr/bin/composer', true);
        $this->addChrootExtension($userDir, '/usr/share/doc/composer');
    }

    public function fixGroupNames($user, $userDir)
    {
        $this->addChrootExtension($userDir, '/lib/x86_64-linux-gnu/libnss_files.so.2');

        $allowGroupNames = [
            'root',
            'www-data',
            $user,
            $this->server()->getHostingUserGroup(),
            $this->chrootGroup,
        ];

        exec('rm -rf '.$userDir.'/etc/group && cat /etc/group | grep "'.implode('\|', $allowGroupNames).'" >> '.$userDir.'/etc/group', $output);
        exec('rm -rf '.$userDir.'/etc/passwd && cat /etc/passwd | grep "'.implode('\|', $allowGroupNames).'" >> '.$userDir.'/etc/passwd', $output);
    }

    public function fixGitSupport($userDir)
    {
        $this->addChrootExtension($userDir, '/usr/bin/git', true);
        $this->addChrootExtension($userDir, '/usr/share/git-core');
        $this->addChrootExtension($userDir, '/usr/lib/git-core');

        //Allow https for git clone
        $this->addChrootExtension($userDir, '/usr/lib/git-core/git-remote-https', true);

        //Install all git extensions
        // foreach (array_slice(scandir($gitStoreExt = '/usr/lib/git-core'), 2) as $extension) {
        //     $this->addChrootExtension($userDir, $gitStoreExt.'/'.$extension, true);
        // }
    }

    public function fixDNSResolving($userDir)
    {
        $this->addChrootExtension($userDir, '/lib/x86_64-linux-gnu/libnss_dns.so.2', true);
        $this->addChrootExtension($userDir, '/etc/resolv.conf', true);
    }

    public function addPhpChrootSupport($userDir)
    {
        //Allow all php versions
        $this->addChrootExtension($userDir, '/usr/bin/php', true);
        $this->addChrootExtension($userDir, '/usr/share/php');

        //Allow all php aliases on system
        foreach ($this->php()->getVersions() as $phpVersion) {
            //If php version is not installed
            if ( ! file_exists('/etc/php/'.$phpVersion) ){
                continue;
            }

            $this->addChrootExtension($userDir, '/etc/php/'.$phpVersion.'/cli');
            $this->addChrootExtension($userDir, '/etc/php/'.$phpVersion.'/mods-available');
            $this->addChrootExtension($userDir, '/usr/bin/php'.$phpVersion, true);
        }

        //Install all php extensions dependencies
        foreach (['20170718', '20180731'] as $phpV) {
            //If phpversion is not installed
            if ( ! file_exists($phpExtPath = '/usr/lib/php/'.$phpV) ) {
                continue;
            }

            foreach (array_slice(scandir($phpExtPath = '/usr/lib/php/'.$phpV), 2) as $extension) {
                $this->addChrootExtension($userDir, $phpExtPath.'/'.$extension, true);
            }
        }

        //Allow primary php alias
        $this->addChrootExtension($userDir, '/etc/alternatives/php', true);
    }

    /*
     * Copy linux extension
     */
    public function addChrootExtension($userDir, $extension, $withDependencies = false)
    {
        createParentDirectory($userDir.'/'.$extension);

        if ( is_dir($extension) ) {
            exec('cp -raL '.$extension.' '.$userDir.'/'.getParentDir($extension), $output);
        }

        else {
            //Copy extension
            exec('cp -raL '.$extension.' '.$userDir.'/'.$extension, $output);

            if ( $withDependencies === true )
            {
                exec('ldd "'.$extension.'" | grep -o \'\(\/.*\s\)\'', $dependencies);

                foreach ($dependencies as $dependency) {
                    createParentDirectory($userDir.$dependency);

                    exec('cp -rL '.$dependency.' '.$userDir.$dependency);
                }
            }
        }
    }

    /**
     * Update all chroot directoriess
     *
     * @return  [type]
     */
    public function update()
    {
        $wwwPath = vpsManager()->config('www_path');
        $domains = scandir($wwwPath);

        foreach ($domains as $domain) {
            $userPath = $wwwPath.'/'.$domain;

            //If is not chroot directory
            if (
                !is_dir($userPath)
                || in_array($domain, ['.', '..'])
                || !isValidDomain($domain)
                || ! $this->isChroot($userPath)
            ) {
                continue;
            }

            $this->create($domain, [
                'chroot' => true
            ]);
        }

        return $this->response()->success('All chroot directories has been updated.');
    }
}

?>