<?php

use Gogol\VpsManagerCLI\Application;

/**
 * Get the VPS Manager root path.
 *
 * @return string
 */
function vpsManagerPath(): string
{
    return __DIR__.'/..';
}

/**
 * Get the shared VPS Manager application instance.
 *
 * @return \Gogol\VpsManagerCLI\Application
 */
function vpsManager(): Application
{
    static $vpsManager = null;

    // Boot the application only once
    return $vpsManager ??= new Application();
}

/**
 * Determine if the given domain is valid (at least second level domain).
 *
 * @param  string|null  $domain
 * @return bool
 */
function isValidDomain($domain = null): bool
{
    // We want at least one dot in the domain name
    if (! is_string($domain) || ! str_contains($domain, '.')) {
        return false;
    }

    return filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
}

/**
 * Determine if the given email address is valid.
 *
 * @param  string|null  $email
 * @return bool
 */
function isValidEmail($email = null): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

if (! function_exists('trim_end')) {
    /**
     * Remove all occurrences of the given suffix from the end of the string.
     *
     * @param  string|null  $string
     * @param  string  $trim
     * @return string
     */
    function trim_end($string, $trim): string
    {
        $string = (string) $string;

        if ($trim === '') {
            return $string;
        }

        while (str_ends_with($string, $trim)) {
            $string = substr($string, 0, -strlen($trim));
        }

        return $string;
    }
}

/**
 * Generate a random password.
 *
 * @param  int  $length
 * @return string
 */
function getRandomPassword(int $length = 20): string
{
    $pool = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ?:!._';
    $max = strlen($pool) - 1;

    $password = '';

    for ($i = 0; $i < $length; $i++) {
        $password .= $pool[random_int(0, $max)];
    }

    return $password;
}

/**
 * Check if the application is running under the root user.
 *
 * @return void
 *
 * @throws \Exception
 */
function checkPermissions(): void
{
    $user = trim((string) shell_exec('whoami'));

    if ($user !== 'root') {
        throw new Exception('VPS Manager can be booted only under the root user.');
    }
}

/**
 * Create given directories with permissions.
 *
 * @param  array  $paths
 * @param  string  $user
 * @param  array  $config
 * @param  callable|null  $callback
 * @param  bool  $message
 * @return void
 */
function createDirectories($paths, $user, $config = [], $callback = null, $message = true): void
{
    foreach ($paths as $path => $permissions) {
        if (file_exists($path)) {
            continue;
        }

        if (isset($permissions['mknod'])) {
            createParentDirectory($path);

            shell_exec('mknod -m '.$permissions['mknod'][0].' '.$path.' '.$permissions['mknod'][1]);

            continue;
        }

        shell_exec('mkdir -p '.$path);

        // Callback on created directory
        if (isset($callback)) {
            $callback($path, $permissions);
        }

        // Change permissions of the newly created directory
        if (! isset($config['no_chmod'])) {
            $dir_chmod = $permissions['chmod'] ?? $permissions;
            $dir_user = $permissions['user'] ?? $user;
            $dir_group = $permissions['group'] ?? 'www-data';

            shell_exec('chmod '.$dir_chmod.' -R '.$path.' && chmod g+s -R '.$path.' && chown -R '.$dir_user.':'.$dir_group.' '.$path);
        }

        if ($message == true) {
            vpsManager()
                ->response()
                ->message('Directory created: <comment>'.$path.'</comment>')
                ->writeln();
        }
    }
}

/**
 * Get the parent directory of the given directory.
 *
 * @param  string  $directory
 * @return string
 */
function getParentDir($directory): string
{
    return implode('/', array_slice(explode('/', $directory), 0, -1));
}

/**
 * Create the parent directory if it is missing.
 *
 * @param  string  $directory
 * @return void
 */
function createParentDirectory($directory): void
{
    $parentDir = getParentDir($directory);

    // Create missing parent directory
    if (! file_exists($parentDir)) {
        shell_exec('mkdir -p '.$parentDir.' && chmod 701 -R '.$parentDir.' && chmod g+s -R '.$parentDir.' && chown -R root:root '.$parentDir);
    }
}

/**
 * Get the total size of the directory in bytes.
 *
 * @param  string  $path
 * @return int
 */
function getDirectorySize($path): int
{
    $bytesTotal = 0;
    $path = realpath($path);

    if ($path !== false && $path != '' && file_exists($path)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $object) {
            $bytesTotal += $object->getSize();
        }
    }

    return $bytesTotal;
}
