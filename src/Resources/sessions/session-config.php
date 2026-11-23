<?php

/*
 * Prints the session configuration of a Laravel application as JSON: {"driver":…,"lifetime":…,"files":…}.
 *
 * Usage: php -- <application path> < session-config.php, run as the owner of the hosting by sessions:clean.
 *
 * Only config/session.php is included, the application is not booted: old applications (Laravel 5) cannot boot
 * on a newer PHP and have no config:show. The helpers used by config/session.php are replaced by minimal
 * versions, env() reads the .env file of the application.
 */

namespace Illuminate\Support {
    if (! class_exists(Str::class, false)) {
        class Str
        {
            /**
             * Only the name of the cookie uses Str (Str::slug), its value does not matter.
             *
             * @param  string  $method
             * @param  array  $arguments
             * @return mixed
             */
            public static function __callStatic($method, $arguments)
            {
                return $arguments[0] ?? '';
            }
        }
    }
}

namespace {
    $vpsmanagerApp = rtrim((string) ($argv[1] ?? ''), '/');
    $vpsmanagerEnv = [];

    foreach (@file($vpsmanagerApp.'/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (! preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/', $line, $match)) {
            continue;
        }

        $value = trim($match[2]);

        if (preg_match('/^"(.*)"$/', $value, $quoted) || preg_match("/^'(.*)'$/", $value, $quoted)) {
            $value = $quoted[1];
        } else {
            // An inline comment of an unquoted value
            $value = trim(preg_replace('/\s+#.*$/', '', $value));
        }

        $vpsmanagerEnv[$match[1]] = $value;
    }

    if (! function_exists('env')) {
        /**
         * Value of the .env file of the application, converted like env() of Laravel.
         *
         * @param  string  $key
         * @param  mixed  $default
         * @return mixed
         */
        function env($key, $default = null)
        {
            global $vpsmanagerEnv;

            if (! array_key_exists($key, $vpsmanagerEnv)) {
                return $default instanceof Closure ? $default() : $default;
            }

            switch (strtolower($vpsmanagerEnv[$key])) {
                case 'true':
                case '(true)':
                    return true;
                case 'false':
                case '(false)':
                    return false;
                case 'empty':
                case '(empty)':
                    return '';
                case 'null':
                case '(null)':
                    return null;
            }

            return $vpsmanagerEnv[$key];
        }
    }

    if (! function_exists('storage_path')) {
        /**
         * Path in the storage directory of the application.
         *
         * @param  string  $path
         * @return string
         */
        function storage_path($path = '')
        {
            global $vpsmanagerApp;

            return $vpsmanagerApp.'/storage'.($path !== '' ? '/'.ltrim($path, '/') : '');
        }
    }

    if (! function_exists('base_path')) {
        /**
         * Path in the directory of the application.
         *
         * @param  string  $path
         * @return string
         */
        function base_path($path = '')
        {
            global $vpsmanagerApp;

            return $vpsmanagerApp.($path !== '' ? '/'.ltrim($path, '/') : '');
        }
    }

    if (! function_exists('str_slug')) {
        /**
         * Only the name of the cookie uses str_slug() (Laravel 5), its value does not matter.
         *
         * @param  string  $title
         * @param  string  $separator
         * @return string
         */
        function str_slug($title, $separator = '-')
        {
            return (string) $title;
        }
    }

    try {
        $config = include $vpsmanagerApp.'/config/session.php';

        if (! is_array($config)) {
            throw new RuntimeException('config/session.php does not return an array');
        }

        echo json_encode([
            'driver' => $config['driver'] ?? null,
            'lifetime' => $config['lifetime'] ?? null,
            'files' => $config['files'] ?? null,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);

        exit(1);
    }
}
