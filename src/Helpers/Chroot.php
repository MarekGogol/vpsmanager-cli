<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

class Chroot extends Application
{
    /**
     * The linux group of all chrooted users.
     *
     * @var string
     */
    protected string $chrootGroup = 'vpsmanager_chroot_user';

    /**
     * Create directory tree of a working chroot environment.
     *
     * @param  string  $domain
     * @param  array|null  $config
     * @param  bool  $move_web_data
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function create(string $domain, ?array $config = null, bool $move_web_data = false): Response
    {
        $user = $this->toUserFormat($domain);

        if (! isValidDomain($domain)) {
            return $this->response()->wrongDomainName();
        }

        $userDir = $this->getUserDirPath($domain, $config);
        $web_path = $this->getWebPath($domain, $config);

        $this->response()
            ->success('Setting chroot directory for <info>'.$userDir.'</info>')
            ->writeln();

        // Set chroot permissions of the root directory
        exec('chown root:root '.$userDir.' && chmod 755 '.$userDir);

        $this->server()->createGroupIfNotExists($this->chrootGroup);

        // Add chroot group to the given user
        exec('usermod -a -G '.$this->chrootGroup.' '.$user.' 2> /dev/null');

        // Change user home directory
        $this->server()->changeHomeDir($user, '/data');

        // Move all old web data into the user/data folder
        // Do not move files when the data directory exists already
        if ($move_web_data === true && ! file_exists($web_path)) {
            $webDir = trim($this->getWebDirectory(), '/');

            createDirectories(
                [
                    $web_path => 710,
                ],
                $user,
            );

            exec('cd '.$userDir.'; find . -maxdepth 1 ! -name '.$webDir.' ! -name . -exec mv "{}" '.$webDir.' \;');

            $this->response()
                ->success('All web data moved from <info>'.$userDir.'</info> to <info>'.$userDir.'/'.$webDir.'</info>')
                ->writeln();
        }

        // Hide the login message (data directory must exist at this point)
        $this->disableLoginMessage($web_path);

        createDirectories(
            [
                $userDir.'/tmp' => ['user' => $user, 'group' => $user, 'chmod' => 700],
                $userDir.'/proc' => ['user' => 'root', 'group' => 'root', 'chmod' => 710],
                $userDir.'/dev/null' => ['mknod' => [666, 'c 1 3']],
                $userDir.'/dev/tty' => ['mknod' => [666, 'c 5 0']],
                $userDir.'/dev/random' => ['mknod' => [444, 'c 1 8']],
                $userDir.'/dev/urandom' => ['mknod' => [444, 'c 1 9']],
                // We need chmod 755, because libpng needs to read files from include
                $userDir.'/usr/include' => ['user' => 'root', 'group' => 'root', 'chmod' => 755],
                $userDir.'/usr/lib/x86_64-linux-gnu' => ['user' => 'root', 'group' => 'root', 'chmod' => 755],
                // Writable local directory (e.g. for globally installed node modules)
                $userDir.'/usr/local' => ['user' => 'root', 'group' => 'root', 'chmod' => 777],
            ],
            $user,
            null,
            null,
            false,
        );

        // Allow regular commands
        foreach ([
            '/bin/bash',
            '/bin/sh',
            '/bin/dash',
            '/bin/ls',
            '/bin/ln',
            '/bin/rm',
            '/bin/cp',
            '/bin/which',
            '/bin/mkdir',
            '/bin/chown',
            '/bin/chmod',
            '/bin/cat',
            '/bin/nano',
            '/bin/mv',
            '/usr/bin/id',
            '/usr/bin/groups',
            '/usr/bin/wget',
            '/usr/bin/openssl',
        ] as $command) {
            $this->addChrootExtension($userDir, $command, true);
        }

        $this->addChrootExtension($userDir, '/usr/share/openssh');
        $this->addChrootExtension($userDir, '/usr/bin/whoami', true);
        $this->addChrootExtension($userDir, '/usr/bin/unzip', true);
        $this->addChrootExtension($userDir, '/usr/bin/zip', true);

        // Fix username and hostname in terminal after login into chroot environment
        $this->addChrootExtension($userDir, '/etc/bash.bashrc');
        $this->addChrootExtension($userDir, '/etc/profile');

        // Nano settings
        $this->addChrootExtension($userDir, '/etc/nanorc');
        $this->addChrootExtension($userDir, '/usr/share/nano');

        // Set up clear command and terminal info
        $this->addChrootExtension($userDir, '/lib/terminfo');
        $this->addChrootExtension($userDir, '/usr/bin/clear', true);

        // Allow ssh command
        $this->addChrootExtension($userDir, '/usr/bin/ssh', true);
        $this->addChrootExtension($userDir, '/usr/bin/ssh-keygen', true);

        // Fix ssl certificates for https, ssh etc.
        $this->addChrootExtension($userDir, '/etc/ssl/certs');
        $this->addChrootExtension($userDir, '/etc/ca-certificates.conf');
        $this->addChrootExtension($userDir, '/etc/ca-certificates');
        $this->addChrootExtension($userDir, '/usr/share/ca-certificates');
        $this->addChrootExtension($userDir, '/usr/lib/ssl');

        // Fix group names and user names support
        $this->fixGroupNames($user, $userDir);

        // Fix dns resolving, also required for git
        $this->fixDNSResolving($userDir);

        // Add git command
        $this->fixGitSupport($userDir);

        // Allow timezones (for composer support etc.)
        $this->addChrootExtension($userDir, '/usr/share/zoneinfo');

        // Allow php support in chroot
        $this->addPhpChrootSupport($userDir, $config['php_version'] ?? null);

        // Allow composer support in chroot
        $this->addComposerSupport($userDir);

        // Allow npm + nodejs
        $this->addNodeJs($userDir);

        // Add chroot restriction into sshd_config
        $this->addChrootGroupIntoSSH();

        return $this->response()->success('Chroot for directory <info>'.$userDir.'</info> has been successfully set up.');
    }

    /**
     * Get path of the file which disables the login message.
     *
     * @param  string  $web_path
     * @return string
     */
    public function getNologinFile(string $web_path): string
    {
        return $web_path.'/.hushlogin';
    }

    /**
     * Disable the login message.
     *
     * @param  string  $web_path
     * @return void
     */
    public function disableLoginMessage(string $web_path): void
    {
        $file = $this->getNologinFile($web_path);

        // Create hushlogin file to hide the message
        @file_put_contents($file, '');
    }

    /**
     * Add chroot restriction into sshd_config.
     *
     * @return void
     */
    public function addChrootGroupIntoSSH(): void
    {
        $file = '/etc/ssh/sshd_config';
        $sysBannerPath = '/etc/ssh/vpsmanager_banner.txt';

        $data = file_get_contents($file);

        $section = "\n\n".
            'Match Group '.$this->chrootGroup."\n".
            '    ChrootDirectory /var/www/%u'."\n".
            '    AuthorizedKeysFile /var/www/%u/data/.ssh/authorized_keys'."\n".
            '    Banner '.$sysBannerPath."\n";

        // If section does not exist
        if (! str_contains($data, $this->chrootGroup)) {
            // Set default mode as internal-sftp for working both sftp and ssh
            $data = str_replace("Subsystem\tsftp\t/usr/lib/openssh/sftp-server", "Subsystem\tsftp\tinternal-sftp", $data);

            file_put_contents($file, $data.$section);

            // Restart ssh after sshd_config modification
            $this->ssh()->rebootSSH();
        }

        // Add banner if it does not exist
        if (! file_exists($sysBannerPath)) {
            $bannerPath = (new Stub)->getStubPath('banner.txt');

            exec('cp -r '.$bannerPath.' '.$sysBannerPath);
        }
    }

    /**
     * Check if the given directory is a chroot environment.
     *
     * @param  string  $userDir
     * @return bool
     */
    public function isChroot(string $userDir): bool
    {
        return file_exists($userDir.'/etc/passwd') && file_exists($userDir.'/data') && file_exists($userDir.'/lib');
    }

    /**
     * Remove all chroot directories except data.
     *
     * @param  string  $domain
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function remove(string $domain): Response
    {
        $user = $this->toUserFormat($domain);

        if (! isValidDomain($domain)) {
            return $this->response()->wrongDomainName();
        }

        $userDir = $this->getUserDirPath($domain);
        $webDir = $this->getWebPath($domain);

        // Check if it is a chroot environment
        if (! $this->isChroot($userDir)) {
            return $this->response()->error('<error>This is not a chroot environment.</error>');
        }

        // Unmount directories
        foreach (['proc'] as $dir) {
            if (file_exists($userDir.'/'.$dir)) {
                exec('umount '.$userDir.'/'.$dir);
            }
        }

        // Remove all chroot directories
        foreach (['bin', 'dev', 'etc', 'lib', 'lib64', 'proc', 'tmp', 'usr'] as $dir) {
            if (file_exists($userDir.'/'.$dir)) {
                exec('rm -rf '.$userDir.'/'.$dir);
            }
        }

        // Set default permissions of the root directory
        exec('chown '.$user.':www-data '.$userDir.' && chmod 710 '.$userDir);

        // Remove chroot group from user
        exec('deluser '.$user.' '.$this->chrootGroup.' 2> /dev/null');

        // Change user home directory
        $this->server()->changeHomeDir($user, $webDir);

        // Remove file which disables the login message
        @unlink($this->getNologinFile($webDir));

        return $this->response()->success('Chroot directories have been successfully removed.');
    }

    /**
     * Add nodejs, npm and npx support.
     *
     * We need to add a lot of libraries because of the npm pngquant-bin library
     * which needs gcc/make and many more (see pngquant-bin/lib/install.js).
     *
     * @param  string  $userDir
     * @return void
     */
    public function addNodeJs(string $userDir): void
    {
        // Add nodejs
        $this->addChrootExtension($userDir, '/usr/bin/node', true);
        $this->addChrootExtension($userDir, '/usr/local/bin/node', true);

        // Add npx
        $this->addChrootExtension($userDir, '/usr/bin/npx', true);
        $this->addChrootExtension($userDir, '/usr/local/bin/npx', true);

        // Add npm command
        $this->addChrootExtension($userDir, '/usr/lib/node_modules/npm');
        $this->addChrootExtension($userDir, '/usr/local/lib/node_modules/npm');
        $this->addChrootExtension($userDir, '/usr/local/lib/node_modules/pm2');

        exec('ln -s -f /usr/lib/node_modules/npm/bin/npm-cli.js '.$userDir.'/usr/bin/npm');
        exec('ln -s -f /usr/local/lib/node_modules/npm/bin/npm-cli.js '.$userDir.'/usr/local/bin/npm');

        exec('ln -s -f /usr/lib/node_modules/npm/bin/npx-cli.js '.$userDir.'/usr/bin/npx');
        exec('ln -s -f /usr/local/lib/node_modules/npm/bin/npx-cli.js '.$userDir.'/usr/local/bin/npx');

        exec('ln -s -f /usr/local/lib/node_modules/pm2/bin/pm2 '.$userDir.'/usr/local/bin/pm2');

        // Add c++ libraries support
        $this->addChrootExtension($userDir, '/usr/include');

        // Allow libpng/pngquant support
        $this->addChrootExtension($userDir, '/usr/bin/pngquant');

        // Add required commands for proper npm workflow
        $this->addChrootExtension($userDir, '/usr/bin/env');
        $this->addChrootExtension($userDir, '/usr/bin/ar');
        $this->addChrootExtension($userDir, '/usr/bin/find', true);
        $this->addChrootExtension($userDir, '/bin/uname', true);
        $this->addChrootExtension($userDir, '/bin/grep', true);
        $this->addChrootExtension($userDir, '/usr/bin/install', true);
        $this->addChrootExtension($userDir, '/usr/bin/as', true);
        $this->addChrootExtension($userDir, '/usr/bin/make', true);

        // Allow all required libraries for npm packages
        foreach ([
            '/usr/lib/x86_64-linux-gnu/libpng16.a',
            '/lib/x86_64-linux-gnu/ld-linux-x86-64.so.2',
            '/lib/x86_64-linux-gnu/libmvec.so.1',
            '/usr/lib/x86_64-linux-gnu/libz.so',
            '/usr/lib/x86_64-linux-gnu/Scrt1.o',
            '/usr/lib/x86_64-linux-gnu/crti.o',
            '/usr/lib/x86_64-linux-gnu/libisl.so.19',
            '/usr/lib/x86_64-linux-gnu/libmpc.so.3',
            '/usr/lib/x86_64-linux-gnu/crtn.o',
            '/usr/lib/x86_64-linux-gnu/libc.so',
            '/usr/lib/x86_64-linux-gnu/libc_nonshared.a',
            '/usr/lib/x86_64-linux-gnu/libm.a',
            '/usr/lib/x86_64-linux-gnu/libm-2.27.a',
            '/usr/lib/x86_64-linux-gnu/libm.so',
            '/usr/lib/x86_64-linux-gnu/libmvec.so',
            '/usr/lib/x86_64-linux-gnu/libmvec.a',
            '/usr/lib/x86_64-linux-gnu/libmvec_nonshared.a',
        ] as $library) {
            $this->addChrootExtension($userDir, $library);
        }

        // Allow gcc
        $this->allowGcc($userDir);

        // Mount proc if it is not mounted yet
        if (! file_exists($userDir.'/proc/cpuinfo')) {
            exec('mount --bind /proc '.$userDir.'/proc');
        }
    }

    /**
     * Add gcc compiler support.
     *
     * @param  string  $userDir
     * @return void
     */
    public function allowGcc(string $userDir): void
    {
        exec('gcc --version', $gccVersionOutput);

        // Last word of the first line is the gcc version
        $gccVersion = explode(' ', $gccVersionOutput[0] ?? '');
        $gccVersion = end($gccVersion);

        $this->addChrootExtension($userDir, '/usr/bin/gcc', true);
        $this->addChrootExtension($userDir, '/usr/bin/gcc-7', true);
        $this->addChrootExtension($userDir, '/usr/lib/gcc');
        $this->addChrootExtension($userDir, '/usr/lib/gcc/x86_64-linux-gnu/'.$gccVersion.'/cc1', true);
        $this->addChrootExtension($userDir, '/usr/lib/gcc/x86_64-linux-gnu/'.$gccVersion.'/collect2', true);

        // Allow linker
        $this->addChrootExtension($userDir, '/usr/bin/ld', true);
        $this->addChrootExtension($userDir, '/usr/bin/ld.gold', true);
        $this->addChrootExtension($userDir, '/usr/bin/ld.bfd', true);
    }

    /**
     * Add composer support.
     *
     * @param  string  $userDir
     * @return void
     */
    public function addComposerSupport(string $userDir): void
    {
        $this->addChrootExtension($userDir, '/usr/bin/composer', true);
        $this->addChrootExtension($userDir, '/usr/local/bin/composer', true);
        $this->addChrootExtension($userDir, '/usr/share/doc/composer');
    }

    /**
     * Copy allowed groups and users into the chroot passwd and group files.
     *
     * @param  string  $user
     * @param  string  $userDir
     * @return void
     */
    public function fixGroupNames(string $user, string $userDir): void
    {
        $this->addChrootExtension($userDir, '/lib/x86_64-linux-gnu/libnss_files.so.2');

        $allowGroupNames = ['root', 'www-data', $user, $this->server()->getHostingUserGroup(), $this->chrootGroup];

        exec('rm -rf '.$userDir.'/etc/group && cat /etc/group | grep "'.implode('\|', $allowGroupNames).'" >> '.$userDir.'/etc/group');
        exec('rm -rf '.$userDir.'/etc/passwd && cat /etc/passwd | grep "'.implode('\|', $allowGroupNames).'" >> '.$userDir.'/etc/passwd');
    }

    /**
     * Add git support.
     *
     * @param  string  $userDir
     * @return void
     */
    public function fixGitSupport(string $userDir): void
    {
        $this->addChrootExtension($userDir, '/usr/bin/git', true);
        $this->addChrootExtension($userDir, '/usr/share/git-core');
        $this->addChrootExtension($userDir, '/usr/lib/git-core');

        // Allow https for git clone
        $this->addChrootExtension($userDir, '/usr/lib/git-core/git-remote-https', true);
    }

    /**
     * Add dns resolving support.
     *
     * @param  string  $userDir
     * @return void
     */
    public function fixDNSResolving(string $userDir): void
    {
        $this->addChrootExtension($userDir, '/lib/x86_64-linux-gnu/libnss_dns.so.2', true);
        $this->addChrootExtension($userDir, '/etc/resolv.conf', true);
    }

    /**
     * Add php support with all installed versions and extensions.
     *
     * @param  string  $userDir
     * @param  string|null  $usePhpCliVersion
     * @return void
     */
    public function addPhpChrootSupport(string $userDir, ?string $usePhpCliVersion = null): void
    {
        // Allow php binary
        $this->addChrootExtension($userDir, '/usr/bin/php', true);
        $this->addChrootExtension($userDir, '/usr/share/php');

        // Allow php iconv
        $this->addChrootExtension($userDir, '/usr/bin/iconv', true);
        $this->addChrootExtension($userDir, '/usr/lib/x86_64-linux-gnu/gconv');

        // Allow all php versions installed on the system
        foreach ($this->php()->getVersions() as $phpVersion) {
            // Skip php version which is not installed
            if (! file_exists('/etc/php/'.$phpVersion.'/fpm')) {
                continue;
            }

            $this->addChrootExtension($userDir, '/etc/php/'.$phpVersion.'/cli');
            $this->addChrootExtension($userDir, '/etc/php/'.$phpVersion.'/mods-available');
            $this->addChrootExtension($userDir, '/usr/bin/php'.$phpVersion, true);
        }

        // Install extensions dependencies of all php API versions (directories like 20240924)
        $phpUsrDir = '/usr/lib/php/';

        foreach (is_dir($phpUsrDir) ? scandir($phpUsrDir) : [] as $phpApiVersion) {
            $phpExtPath = $phpUsrDir.$phpApiVersion;

            if (strlen($phpApiVersion) != 8 || ! is_numeric($phpApiVersion) || ! is_dir($phpExtPath)) {
                continue;
            }

            foreach (array_slice(scandir($phpExtPath), 2) as $extension) {
                $this->addChrootExtension($userDir, $phpExtPath.'/'.$extension, true);
            }
        }

        // Allow primary php alias
        $this->addChrootExtension($userDir, '/etc/alternatives/php', true);

        if ($usePhpCliVersion) {
            exec('cp -rf '.$userDir.'/usr/bin/php'.$usePhpCliVersion.' '.$userDir.'/usr/bin/php');
        }
    }

    /**
     * Copy linux file or directory into the chroot environment.
     *
     * @param  string  $userDir
     * @param  string  $extension
     * @param  bool  $withDependencies
     * @return void
     */
    public function addChrootExtension(string $userDir, string $extension, bool $withDependencies = false): void
    {
        createParentDirectory($userDir.'/'.$extension);

        if (is_dir($extension)) {
            exec('cp -raL '.$extension.' '.$userDir.'/'.getParentDir($extension));
        } elseif (file_exists($extension)) {
            // Copy extension
            exec('cp -raL '.$extension.' '.$userDir.'/'.$extension);

            if ($withDependencies === true) {
                exec('ldd "'.$extension.'" | grep -o \'\(\/.*\s\)\'', $dependencies);

                foreach ($dependencies as $dependency) {
                    createParentDirectory($userDir.$dependency);

                    exec('cp -rL '.$dependency.' '.$userDir.$dependency);
                }
            }
        }
    }

    /**
     * Update all chroot directories.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function update(): Response
    {
        $wwwPath = vpsManager()->config('www_path');
        $domains = is_dir($wwwPath) ? scandir($wwwPath) : [];

        foreach ($domains as $domain) {
            $userPath = $wwwPath.'/'.$domain;

            // Skip directories which are not chroot environments
            if (in_array($domain, ['.', '..']) || ! is_dir($userPath) || ! isValidDomain($domain) || ! $this->isChroot($userPath)) {
                continue;
            }

            $this->create($domain, [
                'chroot' => true,
            ]);
        }

        return $this->response()->success('All chroot directories have been updated.');
    }
}
