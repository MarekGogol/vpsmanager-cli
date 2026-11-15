<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

/*
 * Restic binary of the backups: installation of a pinned release, the repository of the configuration and running of
 * its commands without passwords on the command line.
 */
class Restic extends Application
{
    /**
     * Pinned release installed by backup:setup.
     *
     * @var string
     */
    const VERSION = '0.19.1';

    /**
     * Oldest supported release, --stdin-from-command and the exit codes of a missing repository come with 0.17.
     *
     * @var string
     */
    const MINIMAL_VERSION = '0.17.0';

    /**
     * SHA256 of the compressed binaries of the pinned release, from its SHA256SUMS.
     *
     * @var array
     */
    const CHECKSUMS = [
        'amd64' => 'f415415624dcc452f2a02b8c33641791a8c6d6d3b65bbb3543fcf9a25151585c',
        'arm64' => 'a5f64aaab53d51e311fa3829124c5b703f2d14cf187d8640b6be3b2b49376465',
    ];

    /**
     * Path of the installed binary.
     *
     * @var string
     */
    const BINARY = '/usr/local/bin/restic';

    /**
     * Default SSH key of the SFTP repository.
     *
     * @var string
     */
    const KEY_PATH = '/root/.ssh/vpsmanager_backup';

    /**
     * Exit code of restic when the repository does not exist.
     *
     * @var int
     */
    const EXIT_NO_REPOSITORY = 10;

    /**
     * Exit code of restic when the password does not open the repository.
     *
     * @var int
     */
    const EXIT_WRONG_PASSWORD = 12;

    /**
     * Exit code of restic when a snapshot was created, but some files could not be read.
     *
     * @var int
     */
    const EXIT_INCOMPLETE = 3;

    /**
     * Lower priority of the backups on a live server.
     *
     * @var array
     */
    const NICE = ['nice', '-n', '15', 'ionice', '-c2', '-n7'];

    /**
     * Configuration used instead of the saved one, e.g. during backup:setup.
     *
     * @var array|null
     */
    private $settings = null;

    /**
     * Run restic with a lower priority.
     *
     * @var bool
     */
    private $niced = false;

    /**
     * Get an instance which runs restic with a lower priority.
     *
     * @return static
     */
    public function niced(): static
    {
        $restic = clone $this;
        $restic->niced = true;

        return $restic;
    }

    /**
     * Get an instance using the given configuration instead of the saved one.
     *
     * @param  array  $config
     * @return static
     */
    public function using(array $config): static
    {
        $restic = clone $this;
        $restic->settings = $config;

        return $restic;
    }

    /**
     * Get the configuration of the repository.
     *
     * @return array
     */
    public function settings(): array
    {
        return $this->settings ?? vpsManager()->config();
    }

    /**
     * Get the restic repository of the configuration, e.g. /var/vpsmanager_backups/restic or sftp:user@host:restic.
     *
     * @param  array  $config
     * @return string|null
     */
    public static function repository(array $config): ?string
    {
        $type = $config['backup_repository_type'] ?? null;

        if ($type === 'local') {
            return ($config['backup_local_path'] ?? null) ?: null;
        }

        if ($type === 'sftp' && ! empty($config['backup_sftp_host']) && ! empty($config['backup_sftp_user'])) {
            return 'sftp:'.$config['backup_sftp_user'].'@'.$config['backup_sftp_host'].':'.($config['backup_sftp_path'] ?? 'restic');
        }

        return null;
    }

    /**
     * Get the SSH command of the SFTP repository, which sets the port and the key.
     *
     * @param  array  $config
     * @return string
     */
    public static function sftpCommand(array $config): string
    {
        return implode(' ', [
            'ssh',
            '-p', (int) ($config['backup_sftp_port'] ?? 22),
            '-i', $config['backup_sftp_key'] ?? self::KEY_PATH,
            '-o', 'IdentitiesOnly=yes',
            '-o', 'BatchMode=yes',
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'ConnectTimeout=15',
            '-o', 'ServerAliveInterval=60',
            '-o', 'ServerAliveCountMax=10',
            $config['backup_sftp_user'].'@'.$config['backup_sftp_host'],
            '-s', 'sftp',
        ]);
    }

    /**
     * Determine if the configuration has a restic repository with its password.
     *
     * @param  array  $config
     * @return bool
     */
    public static function isConfigured(array $config): bool
    {
        return self::repository($config) !== null && ! empty($config['backup_password']);
    }

    /**
     * Determine if the given restic version is supported.
     *
     * @param  string|null  $version
     * @return bool
     */
    public static function isSupportedVersion(?string $version): bool
    {
        return $version !== null && version_compare($version, self::MINIMAL_VERSION, '>=');
    }

    /**
     * Get the arguments of restic forget from a retention policy, e.g. ['daily' => 7] => ['--keep-daily', '7'].
     *
     * @param  array  $keep
     * @return array
     */
    public static function keepArguments(array $keep): array
    {
        $arguments = [];

        foreach ($keep as $interval => $value) {
            if ($value === null || $value === '' || $value === 0) {
                continue;
            }

            $arguments[] = '--keep-'.$interval;
            $arguments[] = (string) $value;
        }

        return $arguments;
    }

    /**
     * Get the directories of the backup_directories configuration with their excluded patterns.
     *
     * Anything after the first space of an entry are tar-like arguments, only --exclude is supported,
     * e.g. /etc/mysql --exclude="*\/debian.cnf".
     *
     * @param  string  $directories
     * @return array
     */
    public static function directoryEntries(string $directories): array
    {
        $entries = [];

        foreach (array_filter(array_map('trim', explode(';', $directories))) as $entry) {
            $parts = preg_split('/\s+/', $entry);
            $path = rtrim(array_shift($parts), '/') ?: '/';
            $excludes = [];

            foreach ($parts as $part) {
                if (preg_match('/^--exclude=(.+)$/', $part, $match)) {
                    $excludes[] = trim($match[1], '"\'');
                }
            }

            $entries[] = ['path' => $path, 'excludes' => $excludes];
        }

        return $entries;
    }

    /**
     * Get the excluded patterns of the backup of a website.
     *
     * @param  string  $webPath  backed up directory, e.g. /var/www/example.com/data
     * @param  string|null  $ignore  contents of its .backups_ignore
     * @return array
     */
    public static function websiteExcludes(string $webPath, ?string $ignore = null): array
    {
        $webPath = rtrim($webPath, '/');

        // Directories and files excluded anywhere inside the project
        $excludes = ['node_modules', 'vendor', 'cache', 'laravel.log'];

        // Home directory clutter in the root of the website
        foreach (['.config', '.cache', '.local', '.npm', '.pm2', '.nano', '.gnupg', '.bash_history', '.selected_editor'] as $item) {
            $excludes[] = $webPath.'/'.$item;
        }

        foreach (explode("\n", (string) $ignore) as $item) {
            $item = trim($item);

            if ($item === '' || str_starts_with($item, '#')) {
                continue;
            }

            $excludes[] = $webPath.'/'.trim($item, '/');
        }

        return $excludes;
    }

    /**
     * Get the version of the installed restic.
     *
     * @return string|null
     */
    public function installedVersion(): ?string
    {
        foreach ([self::BINARY, trim((string) shell_exec('command -v restic 2>/dev/null'))] as $binary) {
            if ($binary && is_executable($binary)) {
                exec(escapeshellarg($binary).' version 2>/dev/null', $output, $code);

                if ($code === 0 && preg_match('/restic (\d+\.\d+\.\d+)/', implode("\n", $output), $match)) {
                    return $match[1];
                }
            }
        }

        return null;
    }

    /**
     * Get the path of the installed restic.
     *
     * @return string
     */
    public function binary(): string
    {
        if (is_executable(self::BINARY)) {
            return self::BINARY;
        }

        return trim((string) shell_exec('command -v restic 2>/dev/null')) ?: self::BINARY;
    }

    /**
     * Install the pinned release of restic from GitHub into /usr/local/bin, verified with its SHA256.
     *
     * @return true|string true, or the error
     */
    public function install(): bool|string
    {
        $arch = match (php_uname('m')) {
            'x86_64', 'amd64' => 'amd64',
            'aarch64', 'arm64' => 'arm64',
            default => null,
        };

        if (! $arch) {
            return 'Unsupported architecture '.php_uname('m').', install restic '.self::MINIMAL_VERSION.' or newer manually.';
        }

        if (! trim((string) shell_exec('command -v bunzip2 2>/dev/null'))) {
            exec('apt-get install -y bzip2 2>&1', $output, $code);

            if ($code !== 0) {
                return 'bzip2 could not be installed.';
            }
        }

        $directory = sys_get_temp_dir().'/vpsmanager-restic-'.bin2hex(random_bytes(4));
        mkdir($directory, 0700);

        $archive = $directory.'/restic.bz2';
        $url = 'https://github.com/restic/restic/releases/download/v'.self::VERSION.'/restic_'.self::VERSION.'_linux_'.$arch.'.bz2';

        try {
            exec('curl -fsSL --max-time 300 -o '.escapeshellarg($archive).' '.escapeshellarg($url).' 2>&1', $output, $code);

            if ($code !== 0 || ! file_exists($archive)) {
                return 'restic could not be downloaded from '.$url;
            }

            if (hash_file('sha256', $archive) !== self::CHECKSUMS[$arch]) {
                return 'Checksum of the downloaded restic does not match, it was not installed.';
            }

            exec('bunzip2 '.escapeshellarg($archive).' 2>&1', $output, $code);

            if ($code !== 0 || ! file_exists($binary = $directory.'/restic')) {
                return 'restic could not be extracted.';
            }

            if (! copy($binary, self::BINARY) || ! chmod(self::BINARY, 0755)) {
                return 'restic could not be copied into '.self::BINARY;
            }

            return true;
        } finally {
            exec('rm -rf '.escapeshellarg($directory));
        }
    }

    /**
     * Get the environment of restic, the password goes through it and not through the arguments.
     *
     * @param  string|null  $password
     * @return array
     */
    public function env(?string $password = null): array
    {
        $env = getenv();

        $env['RESTIC_REPOSITORY'] = (string) self::repository($this->settings());
        $env['RESTIC_PASSWORD'] = $password ?? (string) ($this->settings()['backup_password'] ?? '');
        $env['HOME'] = $env['HOME'] ?? '/root';

        // Lower memory usage of restic on small servers
        $env['GOGC'] = '25';

        unset($env['RESTIC_PASSWORD_FILE'], $env['RESTIC_PASSWORD_COMMAND']);

        return $env;
    }

    /**
     * Get the global arguments of restic for the repository.
     *
     * @return array
     */
    public function globalArguments(): array
    {
        if (($this->settings()['backup_repository_type'] ?? null) === 'sftp') {
            return ['-o', 'sftp.command='.self::sftpCommand($this->settings())];
        }

        return [];
    }

    /**
     * Run a restic command and capture its output.
     *
     * @param  array  $arguments
     * @param  string|null  $output  standard output
     * @param  string|null  $error  error output
     * @param  string|null  $password  password instead of the configured one
     * @return int exit code
     */
    public function run(array $arguments, ?string &$output = null, ?string &$error = null, ?string $password = null): int
    {
        $command = array_merge($this->niced ? self::NICE : [], [$this->binary()], $this->globalArguments(), $arguments);

        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $this->env($password));

        if (! is_resource($process)) {
            $output = '';
            $error = 'restic could not be started.';

            return 1;
        }

        [$output, $error] = $this->readPipes($pipes[1], $pipes[2]);

        return proc_close($process);
    }

    /**
     * Run a shell pipeline with restic, e.g. restic dump into mysql, with pipefail.
     *
     * @param  string  $pipeline  command where {restic} is replaced by restic with its global arguments
     * @param  string|null  $error
     * @return int exit code
     */
    public function pipeline(string $pipeline, ?string &$error = null): int
    {
        $restic = implode(' ', array_map('escapeshellarg', array_merge([$this->binary()], $this->globalArguments())));

        $process = proc_open(
            ['bash', '-o', 'pipefail', '-c', str_replace('{restic}', $restic, $pipeline)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $this->env(),
        );

        if (! is_resource($process)) {
            $error = 'restic could not be started.';

            return 1;
        }

        [, $error] = $this->readPipes($pipes[1], $pipes[2]);

        return proc_close($process);
    }

    /**
     * Run a restic command connected to the terminal.
     *
     * @param  array  $arguments
     * @return int exit code
     */
    public function passthru(array $arguments): int
    {
        $command = array_merge([$this->binary()], $this->globalArguments(), $arguments);

        $process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, null, $this->env());

        return is_resource($process) ? proc_close($process) : 1;
    }

    /**
     * Read both pipes of a process until their end.
     *
     * @param  resource  $stdout
     * @param  resource  $stderr
     * @return array [output, error]
     */
    private function readPipes($stdout, $stderr): array
    {
        $result = [(int) $stdout => '', (int) $stderr => ''];
        $open = [$stdout, $stderr];

        while ($open) {
            $read = $open;
            $write = $except = null;

            if (stream_select($read, $write, $except, null) === false) {
                break;
            }

            foreach ($read as $pipe) {
                $chunk = fread($pipe, 65536);

                if ($chunk === '' || $chunk === false) {
                    fclose($pipe);
                    $open = array_filter($open, fn ($item) => $item !== $pipe);

                    continue;
                }

                $result[(int) $pipe] .= $chunk;
            }
        }

        return [$result[(int) $stdout], $result[(int) $stderr]];
    }

    /**
     * Get the snapshots of the repository, oldest first.
     *
     * @param  array  $arguments  filters, e.g. ['--tag', 'databases']
     * @param  string|null  $error
     * @return array|null null on failure
     */
    public function snapshots(array $arguments = [], ?string &$error = null): ?array
    {
        if ($this->run(array_merge(['snapshots', '--json'], $arguments), $output, $error) !== 0) {
            return null;
        }

        $snapshots = json_decode($output, true);

        return is_array($snapshots) ? $snapshots : null;
    }

    /**
     * Get the last line of a restic error output, its message.
     *
     * @param  string|null  $error
     * @return string
     */
    public static function errorMessage(?string $error): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $error))));

        return $lines ? end($lines) : 'unknown error';
    }
}
