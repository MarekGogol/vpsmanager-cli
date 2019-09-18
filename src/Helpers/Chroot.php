<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

class Chroot extends Application
{
    /*
     * Create directory tree for working chroot
     */
    public function create($domain, $config = null, $move_web_data = false)
    {
        $user = $this->toUserFormat($domain);

        if ( ! isValidDomain($domain) )
            return $this->response()->wrongDomainName();

        $web_root_path = $this->getWebRootPath($domain, $config);
        $web_path = $this->getWebPath($domain, $config);

        //Move all old data to
        if ( $move_web_data === true ) {

            //Dont move files when data directory exists already
            if ( ! file_exists($web_path) )
            {
                $webDir = trim($this->getWebDirectory(), '/');

                createDirectories([
                    $web_path => 710,
                ], $user);

                exec('cd '.$web_root_path.'; find . -maxdepth 1 ! -name '.$webDir.' ! -name . -exec mv "{}" '.$webDir.' \;');
            }
        }

        $this->response()->success('Setting chroot directory for <info>'.$web_root_path.'</info>...')->writeln();

        //Clone bashrc
        // exec('cp -Rf '.__DIR__.'/../Resources/user/.bash_profile '.$web_root_path.'/data');

        createDirectories([
            $web_root_path.'/tmp' => ['user' => $domain, 'group' => $domain, 'chmod' => 700],
            $web_root_path.'/proc' => ['user' => 'root', 'group' => 'root', 'chmod' => 710],
            $web_root_path.'/dev/null' => ['mknod' => [666, 'c 1 3']],
            $web_root_path.'/dev/tty' => ['mknod' => [666, 'c 5 0']],
            $web_root_path.'/dev/random' => ['mknod' => [444, 'c 1 8']],
            $web_root_path.'/dev/urandom' => ['mknod' => [444, 'c 1 1']],
            $web_root_path.'/usr/include' => ['user' => 'root', 'group' => 'root', 'chmod' => 755], //we need chmood 755, because libpng needs to read files from include
            $web_root_path.'/usr/lib/x86_64-linux-gnu' => ['user' => 'root', 'group' => 'root', 'chmod' => 755], //we need chmood 755, because libpng needs to read files from include
            $web_root_path.'/usr/local' => ['user' => 'root', 'group' => 'root', 'chmod' => 777], //we need chmood 755, because libpng needs to read files from include
        ], $user);

        //Allow regularcommands
        $this->addChrootExtension($web_root_path, '/bin/bash', true);
        $this->addChrootExtension($web_root_path, '/etc/bash.bashrc');
        $this->addChrootExtension($web_root_path, '/bin/sh', true);
        $this->addChrootExtension($web_root_path, '/bin/dash', true);
        $this->addChrootExtension($web_root_path, '/bin/ls', true);
        $this->addChrootExtension($web_root_path, '/bin/rm', true);
        $this->addChrootExtension($web_root_path, '/bin/cp', true);
        $this->addChrootExtension($web_root_path, '/bin/mkdir', true);
        $this->addChrootExtension($web_root_path, '/bin/chown', true);
        $this->addChrootExtension($web_root_path, '/bin/chmod', true);
        $this->addChrootExtension($web_root_path, '/bin/cat', true);
        $this->addChrootExtension($web_root_path, '/bin/nano', true);
        $this->addChrootExtension($web_root_path, '/usr/bin/id', true);
        $this->addChrootExtension($web_root_path, '/usr/bin/groups', true);
        $this->addChrootExtension($web_root_path, '/usr/bin/wget', true);
        $this->addChrootExtension($web_root_path, '/usr/bin/openssl', true);
        $this->addChrootExtension($web_root_path, '/usr/share/openssh');

        //Set up clear command and terminal info
        $this->addChrootExtension($web_root_path, '/lib/terminfo');
        $this->addChrootExtension($web_root_path, '/usr/bin/clear', true);

        //Allow ssh command
        $this->addChrootExtension($web_root_path, '/usr/bin/ssh', true);
        $this->addChrootExtension($web_root_path, '/usr/bin/ssh-keygen', true);

        //Fix ssl certificates for https,ssh etc...
        // $this->addChrootExtension($web_root_path, '/etc/ssl/certs/ca-certificates.crt');
        $this->addChrootExtension($web_root_path, '/etc/ssl/certs');
        $this->addChrootExtension($web_root_path, '/etc/ca-certificates.conf');
        $this->addChrootExtension($web_root_path, '/etc/ca-certificates');
        $this->addChrootExtension($web_root_path, '/usr/share/ca-certificates');
        $this->addChrootExtension($web_root_path, '/usr/lib/ssl');
        $this->addChrootExtension($web_root_path, '/usr/share/ca-certificates');

        //Gix groups names and user names support
        $this->fixGroupNames($user, $web_root_path);

        //Fix dns resolving, also required for proper git working
        $this->fixDNSResolving($web_root_path);

        //Add git command
        $this->fixGitSupport($web_root_path);

        //Allow timezone (for composer support etc..)
        $this->addChrootExtension($web_root_path, '/usr/share/zoneinfo');

        //Allow php support in chroot
        $this->addPhpChrootSupport($web_root_path);

        //Allow composer support in chroot
        $this->addComposerSupport($web_root_path);

        //Allow npm + nodejs
        $this->addNodeJs($web_root_path);

        return $this->response()->success('Chroot for directory <info>'.$web_root_path.'</info> has been successfully setted up.');
    }

    /*
     * Remove all chroot directories except data
     */
    public function remove($domain, $moveWebdata = false)
    {
        $user = $this->toUserFormat($domain);

        if ( ! isValidDomain($domain) )
            return $this->response()->wrongDomainName();

        $web_root_path = $this->getWebRootPath($domain);

        //Check if is chroot environment
        if ( ! file_exists($web_root_path.'/data') ) {
            return $this->response()->error('<error>This is not chroot environment.</error>');
        }

        //Unmount directories
        foreach (['proc'] as $dir) {
            if ( file_exists($web_root_path.'/'.$dir) ) {
                exec('umount '.$web_root_path.'/'.$dir, $output);
            }
        }

        //Remove all chroot directories
        foreach (['bin', 'dev', 'etc', 'lib', 'lib64', 'proc', 'tmp', 'usr'] as $dir) {
            if ( file_exists($web_root_path.'/'.$dir) ) {
                exec('rm -rf '.$web_root_path.'/'.$dir, $output);
            }
        }

        //Move web data from data to web dir
        if ( $moveWebdata === true )
        {
            if ( file_exists($web_root_path.'/data') ) {
                exec('cd '.$web_root_path.'/data && find . -name . -o -exec sh -c \'mv -- "$@" "$0"\' ../ {} + -type d -prune', $output, $return_var0);

                //If data has been successfuly moved
                if ( $return_var0 === 0 ){
                    exec('rm -rf '.$web_root_path.'/data', $output, $return_var1);
                }

                //Check if directories has been successfully moved.
                if ( $return_var0 === 0 && isset($return_var1) && $return_var1 == 0 ) {
                    $this->response()->success('Web data has been successfully moved from <info>/data</info> into root domain directory.')->writeln();
                } else {
                    $this->response()->message('<error>Web data could not be moved from</error> <info>/data</info> <error>into root domain directory.</error>')->writeln();
                }
            }
        }

        return $this->response()->success('Chroot directories has been successfully removed.');
    }

    /**
     * We need add lot of libraries because of npm pngquant-bin library
     * which needs gcc/make and many more...
     * see pngquant-bin/lib/install.js
     */
    public function addNodeJs($web_root_path)
    {
        //Add nodejs
        $this->addChrootExtension($web_root_path, '/usr/bin/node', true);

        //Add npm command
        $this->addChrootExtension($web_root_path, '/usr/lib/node_modules/npm');
        exec('ln -s -f /usr/lib/node_modules/npm/bin/npm-cli.js '.$web_root_path.'/usr/bin/npm', $output);

        //Add cpp+ libraries support
        $this->addChrootExtension($web_root_path, '/usr/include');

        //Allow libpng/pnguant support
        $this->addChrootExtension($web_root_path, '/usr/bin/pngquant');

        //Added required commands for proper npm workflow
        $this->addChrootExtension($web_root_path, '/usr/bin/env');
        $this->addChrootExtension($web_root_path, '/usr/bin/ar');
        $this->addChrootExtension($web_root_path, '/usr/bin/find', true);
        $this->addChrootExtension($web_root_path, '/bin/uname', true);
        $this->addChrootExtension($web_root_path, '/bin/grep', true);
        $this->addChrootExtension($web_root_path, '/usr/bin/install', true);
        $this->addChrootExtension($web_root_path, '/usr/bin/as', true);
        $this->addChrootExtension($web_root_path, '/usr/bin/make', true);

        //Allow all required libraries for npm packages
        $this->addChrootExtension($web_root_path, '/usr/lib/x86_64-linux-gnu/libpng16.a');
        $this->addChrootExtension($web_root_path, '/lib/x86_64-linux-gnu/ld-linux-x86-64.so.2');
        $this->addChrootExtension($web_root_path, '/lib/x86_64-linux-gnu/libmvec.so.1');
        $this->addChrootExtension($web_root_path, '/usr/lib/x86_64-linux-gnu/libz.so');
        $this->addChrootExtension($web_root_path, '/usr/lib/x86_64-linux-gnu/Scrt1.o');
        $this->addChrootExtension($web_root_path, '/usr/lib/x86_64-linux-gnu/crti.o');
        $this->addChrootExtension($web_root_path, '/usr/lib/x86_64-linux-gnu/libisl.so.19');
        $this->addChrootExtension($web_root_path, '/usr/lib/x86_64-linux-gnu/libmpc.so.3');
        // $this->addChrootExtension($web_root_path, '/usr/lib/x86_64-linux-gnu/libmpfr.so');
        $this->addChrootExtension($web_root_path, '/usr/lib/x86_64-linux-gnu/crtn.o');
        $this->addChrootExtension($web_root_path, '/usr/lib/x86_64-linux-gnu/libc.so');
        $this->addChrootExtension($web_root_path, '/usr/lib/x86_64-linux-gnu/libc_nonshared.a');
        $this->addChrootExtension($web_root_path, '/usr/lib/x86_64-linux-gnu/libm.a');
        $this->addChrootExtension($web_root_path, '/usr/lib/x86_64-linux-gnu/libm-2.27.a');
        $this->addChrootExtension($web_root_path, '/usr/lib/x86_64-linux-gnu/libm.so');
        $this->addChrootExtension($web_root_path, '/usr/lib/x86_64-linux-gnu/libmvec.so');
        $this->addChrootExtension($web_root_path, '/usr/lib/x86_64-linux-gnu/libmvec.a');
        $this->addChrootExtension($web_root_path, '/usr/lib/x86_64-linux-gnu/libmvec_nonshared.a');

        //Allow gcc
        $this->allowGcc($web_root_path);

        //If proc is not mounted
        if ( ! file_exists($web_root_path.'/proc/cpuinfo') ) {
            exec('mount --bind /proc '.$web_root_path.'/proc', $output);
        }
    }

    public function allowGcc($web_root_path)
    {
        $this->addChrootExtension($web_root_path, '/usr/bin/gcc', true);
        $this->addChrootExtension($web_root_path, '/usr/bin/gcc-7', true);
        $this->addChrootExtension($web_root_path, '/usr/lib/gcc');
        $this->addChrootExtension($web_root_path, '/usr/lib/gcc/x86_64-linux-gnu/7.4.0/cc1', true);
        $this->addChrootExtension($web_root_path, '/usr/lib/gcc/x86_64-linux-gnu/7.4.0/collect2', true);

        //Allow ldd
        $this->addChrootExtension($web_root_path, '/usr/bin/ld', true);
        $this->addChrootExtension($web_root_path, '/usr/bin/ld.gold', true);
        $this->addChrootExtension($web_root_path, '/usr/bin/ld.bfd', true);

        // $gcc = glob('/usr/lib/gcc/x86_64-linux-gnu/**/**/**');

        // foreach ($gcc as $path) {
        //     $this->addChrootExtension($web_root_path, $path, true);
        // }
    }

    public function addComposerSupport($web_root_path)
    {
        $this->addChrootExtension($web_root_path, '/usr/bin/composer', true);
        $this->addChrootExtension($web_root_path, '/usr/share/doc/composer');
    }

    public function fixGroupNames($user, $web_root_path)
    {
        $this->addChrootExtension($web_root_path, '/lib/x86_64-linux-gnu/libnss_files.so.2');

        $allowGroupNames = [
            'root',
            'www-data',
            $user,
            $this->server()->getHostingUserGroup(),
        ];

        exec('cat /etc/group | grep "'.implode('\|', $allowGroupNames).'" >> '.$web_root_path.'/etc/group', $output);
        exec('cat /etc/passwd | grep "'.implode('\|', $allowGroupNames).'" >> '.$web_root_path.'/etc/passwd', $output);
    }

    public function fixGitSupport($web_root_path)
    {
        $this->addChrootExtension($web_root_path, '/usr/bin/git', true);
        $this->addChrootExtension($web_root_path, '/usr/share/git-core');
        $this->addChrootExtension($web_root_path, '/usr/lib/git-core');

        //Allow https for git clone
        $this->addChrootExtension($web_root_path, '/usr/lib/git-core/git-remote-https', true);

        //Install all git extensions
        // foreach (array_slice(scandir($gitStoreExt = '/usr/lib/git-core'), 2) as $extension) {
        //     $this->addChrootExtension($web_root_path, $gitStoreExt.'/'.$extension, true);
        // }
    }

    public function fixDNSResolving($web_root_path)
    {
        $this->addChrootExtension($web_root_path, '/lib/x86_64-linux-gnu/libnss_dns.so.2', true);
        $this->addChrootExtension($web_root_path, '/etc/resolv.conf', true);
    }

    public function addPhpChrootSupport($web_root_path)
    {
        //Allow all php versions
        $this->addChrootExtension($web_root_path, '/usr/bin/php', true);
        $this->addChrootExtension($web_root_path, '/usr/share/php');

        //Allow all php aliases on system
        foreach ($this->php()->getVersions() as $phpVersion) {
            //If php version is not installed
            if ( ! file_exists('/etc/php/'.$phpVersion) ){
                continue;
            }

            $this->addChrootExtension($web_root_path, '/etc/php/'.$phpVersion.'/cli');
            $this->addChrootExtension($web_root_path, '/etc/php/'.$phpVersion.'/mods-available');
            $this->addChrootExtension($web_root_path, '/usr/bin/php'.$phpVersion, true);
        }

        //Install all php extensions dependencies
        foreach (['20170718', '20180731'] as $phpV) {
            //If phpversion is not installed
            if ( ! file_exists($phpExtPath = '/usr/lib/php/'.$phpV) ) {
                continue;
            }

            foreach (array_slice(scandir($phpExtPath = '/usr/lib/php/'.$phpV), 2) as $extension) {
                $this->addChrootExtension($web_root_path, $phpExtPath.'/'.$extension, true);
            }
        }

        //Allow primary php alias
        $this->addChrootExtension($web_root_path, '/etc/alternatives/php', true);
    }

    /*
     * Copy linux extension
     */
    public function addChrootExtension($web_root_path, $extension, $withDependencies = false)
    {
        createParentDirectory($web_root_path.'/'.$extension);

        if ( is_dir($extension) ) {
            exec('cp -raL '.$extension.' '.$web_root_path.'/'.getParentDir($extension), $output);
        }

        else {
            //Copy extension
            exec('cp -raL '.$extension.' '.$web_root_path.'/'.$extension, $output);

            if ( $withDependencies === true )
            {
                exec('ldd "'.$extension.'" | grep -o \'\(\/.*\s\)\'', $dependencies);

                foreach ($dependencies as $dependency) {
                    createParentDirectory($web_root_path.$dependency);

                    exec('cp -rL '.$dependency.' '.$web_root_path.$dependency);
                }
            }
        }
    }
}

?>