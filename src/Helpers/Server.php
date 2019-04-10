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
        return shell_exec('getent passwd '.$this->toUserFormat($user)) ? true : false;
    }

    /*
     * Create linux user
     */
    public function createUser(string $user)
    {
        //Check if is user in valid format
        if ( ! isValidDomain($user) )
            return $this->response()->wrongDomainName();

        $user = $this->toUserFormat($user);

        //Check if user exists
        if ( $this->existsUser($user) )
            return $this->response();

        //Web path
        $web_path = $this->getWebPath($user);

        $password = getRandomPassword(16);

        //Create new linux user
        exec('useradd -s /bin/bash -d '.$web_path.' -U '.$user.' -p $(openssl passwd -1 '.$password.')', $output, $return_var);
        if ( $return_var != 0 )
            return $this->response()->error('User could not be created.');

        return $this->response()
                    ->success(
                        '<info>Linux user has been successfully created.</info>'."\n".
                        'User: <comment>'.$user.'</comment>'."\n".
                        'Password: <comment>'.$password.'</comment>'
                   );
    }

    /*
     * Delete user
     */
    public function deleteUser(string $user)
    {
        //Check if is user in valid format
        if ( ! isValidDomain($user) )
            return false;

        $user = $this->toUserFormat($user);

        if ( ! $this->existsUser($user) )
            return true;

        //Create new linux user
        exec('userdel '.$user, $output, $return_var);

        return $return_var == 0 ? true : false;
    }

    /*
     * Return if domain directory exists
     */
    public function existsDomainTree($domain, $config = null)
    {
        if ( isset($config['www_path']) )
            return false;

        return file_exists($this->getWebPath($domain, $config));
    }

    /*
     * Create directory tree for domain
     */
    public function createDomainTree($domain, $config = null)
    {
        $user = $this->toUserFormat($domain);

        if ( ! isValidDomain($domain) )
            return $this->response()->wrongDomainName();

        $web_path = $this->getWebPath($domain, $config);

        //Check if can change permissions of directory
        $with_permissions = ! isset($config['no_chmod']);

        $paths = [
            $web_path => 710,
            $web_path.'/web' => 710,
            $web_path.'/web/public' => 710,
            $web_path.'/sub' => 710,
            $web_path.'/logs' => ['chmod' => 750, 'user' => 'root', 'group' => $user],
        ];

        //Create subdomain
        if ( $sub = $this->getSubdomain($domain) ) {
            $paths[$web_path.'/sub/'.$sub] = 710;
            $paths[$web_path.'/sub/'.$sub.'/public'] = 710;
        }

        //If path has been given
        if ( isset($config['www_path']) )
            $paths = [ $config['www_path'] => 710 ];

        //Create new folders
        foreach ($paths as $path => $permissions)
        {
            if ( ! file_exists($path) ){
                shell_exec('mkdir '.$path);

                if ( substr($path, -7) == '/public' ){
                    $this->getStub('hello.php')->replace('{user}', $domain)->save($path . '/index.php');
                }

                $this->response()->message('Directory created: <comment>'.$path.'</comment>')->writeln();
            }

            //Change permissions
            if ( $with_permissions ){
                $dir_chmod = isset($permissions['chmod']) ? $permissions['chmod'] : $permissions;
                $dir_user = isset($permissions['user']) ? $permissions['user'] : $user;
                $dir_group = isset($permissions['group']) ? $permissions['group'] : 'www-data';
                shell_exec('chmod '.$dir_chmod.' -R '.$path.' && chown -R '.$dir_user.':'.$dir_group.' '.$path);
            }
        }

        return $this->response()->success('Directory <info>'.$web_path.'</info> has been successfully setted up.');
    }

    /*
     * Remove domain tree
     */
    public function deleteDomainTree($domain)
    {
        if ( ! isValidDomain($domain) )
            return false;

        $web_path = vpsManager()->getWebPath($domain);

        return system('rm -rf '.$web_path) == 0;
    }
}

?>