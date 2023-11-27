<?php

/*
 * App instance
 */
$vps_manager = null;

function vpsManagerPath()
{
    return __DIR__ . '/..';
}

function vpsManager()
{
    global $vpsmanager;

    //If vps manager has been already booted
    if ($vpsmanager) {
        return $vpsmanager;
    }

    return $vpsmanager = new Gogol\VpsManagerCLI\Application();
}

function isValidDomain($domain = null)
{
    //We want at least one domain name
    if (strpos($domain, '.') === false) {
        return false;
    }

    return filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
}

function isValidEmail($email = null)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

if (!function_exists('trim_end')) {
    function trim_end($string, $trim)
    {
        while (substr($string, -strlen($trim)) == $trim) {
            $string = substr($string, 0, -strlen($trim));
        }

        return $string;
    }
}

/*
 * Return password
 */
function getRandomPassword($length = 20)
{
    $pool = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ?:!._';

    return substr(str_shuffle(str_repeat($pool, 5)), 0, $length);
}

/*
 * Check app permissions
 */
function checkPermissions()
{
    $user = trim(shell_exec('whoami'));

    if ($user !== 'root') {
        throw new \Exception('This vpsManager can be booted just under root user.');
    }
}

/*
 * Create given folders with permissions
 */
function createDirectories($paths, $user, $config = [], $callback = null, $message = true)
{
    foreach ($paths as $path => $permissions) {
        if (!file_exists($path)) {
            if (isset($permissions['mknod'])) {
                createParentDirectory($path);

                shell_exec('mknod -m ' . $permissions['mknod'][0] . ' ' . $path . ' ' . $permissions['mknod'][1]);
            } else {
                shell_exec('mkdir -p ' . $path);

                //Callback on create direcotry
                if (isset($callback)) {
                    $callback($path, $permissions);
                }

                //Check if can change permissions of directory
                $with_permissions = !isset($config['no_chmod']);

                //Change permissions on new created files
                if ($with_permissions) {
                    $dir_chmod = isset($permissions['chmod']) ? $permissions['chmod'] : $permissions;
                    $dir_user = isset($permissions['user']) ? $permissions['user'] : $user;
                    $dir_group = isset($permissions['group']) ? $permissions['group'] : 'www-data';
                    shell_exec('chmod ' . $dir_chmod . ' -R ' . $path . ' && chmod g+s -R ' . $path . ' && chown -R ' . $dir_user . ':' . $dir_group . ' ' . $path);
                }

                if ($message == true) {
                    vpsManager()
                        ->response()
                        ->message('Directory created: <comment>' . $path . '</comment>')
                        ->writeln();
                }
            }
        }
    }
}

/*
 * Returns parent dir of directory
 */
function getParentDir($directory)
{
    return implode('/', array_slice(explode('/', $directory), 0, -1));
}

/*
 * Create parent directory if is missing
 */
function createParentDirectory($directory)
{
    $parentDir = getParentDir($directory);

    //Create missing parent directory
    if (!file_exists($parentDir)) {
        shell_exec('mkdir -p ' . $parentDir . ' && chmod 701 -R ' . $parentDir . ' && chmod g+s -R ' . $parentDir . ' && chown -R root:root ' . $parentDir);
    }
}

function GetDirectorySize($path)
{
    $bytestotal = 0;
    $path = realpath($path);
    if ($path !== false && $path != '' && file_exists($path)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $object) {
            $bytestotal += $object->getSize();
        }
    }
    return $bytestotal;
}
