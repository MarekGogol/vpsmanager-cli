<?php

namespace Gogol\VpsManagerCLI\Traits;

trait PHPSettingsTrait
{
    /*
     * Get available versions
     */
    public function getVersions()
    {
        return ['7.1', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3'];
    }

    /*
     * Check if given php version is valid from supported list
     */
    public function isValidPHPVersion($version)
    {
        return in_array($version, $this->getVersions());
    }

    private function buildOpenBaseDirs($domain, $config)
    {
        $paths = [$this->getUserDirPath($domain, $config), '/tmp'];

        if (isset($config['open_basedir']) && $config['open_basedir']) {
            if (is_array($config['open_basedir'])) {
                $paths = array_merge($paths, $config['open_basedir']);
            } else {
                $paths[] = $config['open_basedir'];
            }
        }

        return implode(':', $paths);
    }

    protected function phpSettings($domain, $config)
    {
        return [
            'error_log' => $this->getWebPath($domain, $config) . '/' . (isset($config['www_path']) ? '' : 'logs/') . 'php.log',
            'memory_limit' => '256M',
            'upload_max_filesize' => '40M',
            'post_max_size' => '40M',
            'open_basedir' => $this->buildOpenBaseDirs($domain, $config),
            'disable_functions' =>
                'apache_child_terminate,apache_setenv,define_syslog_variables,escapeshellcmd,eval,exec,fp,fput,ftp_connect,ftp_exec,ftp_get,ftp_login,ftp_nb_fput,ftp_put,ftp_raw,ftp_rawlist,get_defined_functions,highlight_file,ini_alter,ini_get_all,ini_restore,inject_code,mysql_pconnect,openlog,passthru,pcntl_alarm,pcntl_async_signals,pcntl_exec,pcntl_fork,pcntl_get_last_error,pcntl_getpriority,pcntl_setpriority,pcntl_signal,pcntl_signal_dispatch,pcntl_signal_get_handler,pcntl_sigprocmask,pcntl_sigtimedwait,pcntl_sigwaitinfo,pcntl_strerror,pcntl_wait,pcntl_waitpid,pcntl_wexitstatus,pcntl_wifcontinued,pcntl_wifexited,pcntl_wifsignaled,pcntl_wifstopped,pcntl_wstopsig,pcntl_wtermsig,phpAds_XmlRpc,phpAds_remoteInfo,phpAds_xmlrpcDecode,phpAds_xmlrpcEncode,php_uname,popen,posix_getpwuid,posix_kill,posix_mkfifo,posix_setpgid,posix_setsid,posix_setuid,posix_uname,proc_nice,proc_terminate,shell_exec,show_source,symlink,syslog,system,xmlrpc_entity_decode',
        ];
    }
}

?>
