<?php

namespace Gogol\VpsManagerCLI\Helpers\Settings;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/*
 * MySQL on a server shared with the web and PHP: innodb_buffer_pool_size by RAM and by the size of the InnoDB data,
 * performance_schema off on a small server (the rule "70-80 % of RAM" holds for a dedicated database server only),
 * and the fixed options of all servers (fixedOptions()).
 */
class MysqlSetting extends Setting
{
    /**
     * Managed option file, read as the last one of the directory.
     */
    const FILE = 'zz-vpsmanager.cnf';

    /**
     * Smallest buffer pool, the default of MySQL.
     */
    const MIN_BUFFER_POOL = 128 * SystemInfo::MB;

    /**
     * performance_schema is turned off up to this RAM, it takes 200-400 MB.
     */
    const PERFORMANCE_SCHEMA_RAM = 4 * SystemInfo::GB;

    /**
     * Sections of the option files read by the server.
     */
    const SECTIONS = '/^(mysqld|server|mariadb|mariadbd|mysqld-[\d.]+|mariadb-[\d.]+)$/';

    /**
     * Fixed options of all servers: [option, value or null for a flag, variable, its expected value, dynamic].
     * Options unknown to the version of the server are left out.
     *
     * @param  string  $version  e.g. 8.0.46
     * @param  bool  $mariadb
     * @param  bool  $replication  the server is a source or a replica
     * @param  bool  $remote  a user may connect from another host
     * @param  bool  $mysqlx  the X Plugin (port 33060) is active
     * @param  bool  $hostnames  a user is defined by a host name, which needs the resolving of the clients
     * @return array
     */
    public static function fixedOptions(string $version, bool $mariadb, bool $replication, bool $remote = false, bool $mysqlx = false, bool $hostnames = true): array
    {
        $mysql = ! $mariadb;

        $options = [
            // Fulltext search finds words of 2 characters (sizes, units) and the English stopwords
            ['innodb_ft_min_token_size', '2', 'innodb_ft_min_token_size', '2', false],
            ['innodb_ft_enable_stopword', 'OFF', 'innodb_ft_enable_stopword', '0', true],
            // Default of MySQL, 16000 is cut by open_files_limit (LimitNOFILE=10000 of systemd) to 4919 anyway
            ['table_open_cache', '4000', 'table_open_cache', '4000', true],
        ];

        if ($mysql && version_compare($version, '8.0.30', '>=')) {
            // Replaces innodb_log_file_size × innodb_log_files_in_group, removed in MySQL 9
            $options[] = ['innodb_redo_log_capacity', '512M', 'innodb_redo_log_capacity', (string) (512 * SystemInfo::MB), true];
        }

        if ($mysql && version_compare($version, '8.4.0', '<')) {
            // Data are not cached twice, in the buffer pool and in the cache of the files; the default since 8.4
            $options[] = ['innodb_flush_method', 'O_DIRECT', 'innodb_flush_method', 'O_DIRECT', false];
        }

        if ($mysql) {
            // Errors and warnings, e.g. the limits changed by the server at its start
            $options[] = ['log_error_verbosity', '2', 'log_error_verbosity', '2', true];
        }

        if ($mysql && ! $replication) {
            // Without a replica the binary log only fills the disk, the backups are dumps
            $options[] = ['disable_log_bin', null, 'log_bin', '0', false];
        }

        // No DNS lookup of every new connection of a client
        if (! $hostnames) {
            $options[] = ['skip_name_resolve', 'ON', 'skip_name_resolve', '1', false];
        }

        // Without a remote user the ports 3306 and 33060 are open to the scanners of the internet only
        if (! $remote && ! $replication) {
            $options[] = ['bind_address', '127.0.0.1', 'bind_address', '127.0.0.1', false];

            if ($mysqlx) {
                $options[] = ['mysqlx_bind_address', '127.0.0.1', 'mysqlx_bind_address', '127.0.0.1', false];
            }
        }

        return $options;
    }

    /**
     * Files of the binary log in the data directory, e.g. binlog.000214 and binlog.index.
     *
     * @param  string  $datadir
     * @return array path => size
     */
    public static function binlogFiles(string $datadir): array
    {
        $files = [];

        foreach (glob(rtrim($datadir, '/').'/binlog.*') ?: [] as $path) {
            if (preg_match('/\/binlog\.(\d{6}|index)$/', $path)) {
                $files[$path] = (int) filesize($path);
            }
        }

        return $files;
    }

    /**
     * Determine if a host of the users is a host name, not localhost nor an address or its pattern.
     *
     * @param  array  $hosts  e.g. localhost, 192.168.1.%, 10.0.0.0/255.0.0.0, app.example.com
     * @return bool
     */
    public static function hasHostnames(array $hosts): bool
    {
        foreach ($hosts as $host) {
            if ($host !== 'localhost' && ! preg_match('/^[0-9a-f.:%_\/]+$/i', $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Options commented out in the other option files: the managed ones and the obsolete ones.
     *
     * @param  array  $fixed  of fixedOptions()
     * @param  string  $version
     * @param  bool  $mariadb
     * @param  bool  $replication
     * @return array
     */
    public static function replacedOptions(array $fixed, string $version, bool $mariadb, bool $replication): array
    {
        $keys = array_column($fixed, 0);

        if (! $mariadb && version_compare($version, '8.0.30', '>=')) {
            $keys = array_merge($keys, ['innodb_log_file_size', 'innodb_log_files_in_group']);
        }

        if (! $mariadb && ! $replication) {
            $keys = array_merge($keys, ['skip_log_bin', 'log_bin', 'binlog_expire_logs_seconds', 'replica_parallel_workers', 'slave_parallel_workers']);
        }

        return $keys;
    }

    /**
     * Key of the setting used by --only.
     *
     * @return string
     */
    public function key(): string
    {
        return 'mysql';
    }

    /**
     * Recommended buffer pool by the nominal RAM and the InnoDB data and its number of instances. The size is a
     * multiple of chunk × instances, as MySQL rounds it.
     *
     * @param  int  $ram
     * @param  int  $data  data and indexes of the InnoDB tables
     * @param  int  $chunk  innodb_buffer_pool_chunk_size
     * @param  bool  $mariadb  MariaDB has no instances of the buffer pool
     * @param  bool  $dedicated  a database server without NGINX and PHP-FPM
     * @return array [size, instances]
     */
    public static function bufferPool(int $ram, int $data, int $chunk = 128 * SystemInfo::MB, bool $mariadb = false, bool $dedicated = false): array
    {
        $gb = SystemInfo::GB;

        $percent = $dedicated ? match (true) {
            $ram <= 4 * $gb => 60,
            $ram <= 16 * $gb => 70,
            default => 75,
        } : match (true) {
            $ram <= 2 * $gb => 25,
            $ram <= 4 * $gb => 30,
            $ram <= 16 * $gb => 40,
            default => 50,
        };

        // A buffer pool bigger than the data does not help
        $size = min(intdiv($ram * $percent, 100), (int) ($data * 1.5));
        $size = max($size, self::MIN_BUFFER_POOL);

        $instances = $mariadb || $size < $gb ? 1 : min(8, intdiv($size, $gb));
        $unit = $chunk * $instances;

        return [max(1, (int) round($size / $unit)) * $unit, $instances];
    }

    /**
     * Comment out the options in the sections of the server, e.g. innodb-buffer-pool-size or
     * loose_performance_schema.
     *
     * @param  string  $content
     * @param  array  $keys  names with underscores
     * @param  string  $note
     * @return array [content, list of the disabled lines]
     */
    public static function disableOptions(string $content, array $keys, string $note): array
    {
        $lines = explode("\n", $content);
        $section = null;
        $disabled = [];

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);

            if (preg_match('/^\[([^\]]+)\]/', $trimmed, $match)) {
                $section = strtolower(trim($match[1]));

                continue;
            }

            if (! $section || ! preg_match(self::SECTIONS, $section) || ! preg_match('/^([a-z0-9_-]+)\s*(=|$)/i', $trimmed, $match)) {
                continue;
            }

            $name = preg_replace('/^loose_/', '', str_replace('-', '_', strtolower($match[1])));

            if (in_array($name, $keys, true)) {
                $lines[$i] = '# '.$note.': '.$trimmed;
                $disabled[] = $trimmed;
            }
        }

        return [implode("\n", $lines), $disabled];
    }

    /**
     * Find the option in the sections of the server.
     *
     * @param  string  $content
     * @param  string  $key
     * @return bool
     */
    public static function hasOption(string $content, string $key): bool
    {
        return static::disableOptions($content, [$key], '')[1] !== [];
    }

    /**
     * Directory of the option files of the server, null without MySQL configuration in /etc/mysql.
     *
     * @return string|null
     */
    protected function directory(): ?string
    {
        foreach (['/etc/mysql/mysql.conf.d', '/etc/mysql/mariadb.conf.d', '/etc/mysql/conf.d'] as $directory) {
            if (is_dir($directory)) {
                return $directory;
            }
        }

        return null;
    }

    /**
     * Option files of MySQL except the managed one.
     *
     * @return array real paths
     */
    protected function optionFiles(): array
    {
        $paths = ['/etc/my.cnf', '/etc/mysql/my.cnf'];

        if (is_dir('/etc/mysql')) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator('/etc/mysql', \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (str_ends_with($file->getFilename(), '.cnf')) {
                    $paths[] = $file->getPathname();
                }
            }
        }

        $files = [];

        foreach ($paths as $path) {
            if (($real = realpath($path)) && is_file($real) && basename($real) !== self::FILE && basename($real) !== 'debian.cnf') {
                $files[$real] = $real;
            }
        }

        return array_values($files);
    }

    /**
     * Content of the managed option file.
     *
     * @param  int  $size
     * @param  int  $instances
     * @param  bool  $performanceSchema  turn performance_schema off
     * @param  bool  $mariadb
     * @param  array  $fixed  of fixedOptions()
     * @return string
     */
    public function content(int $size, int $instances, bool $performanceSchema, bool $mariadb, array $fixed = []): string
    {
        $content = $this->header("MySQL on a server shared with the web and PHP: memory computed by setup:run from RAM and the size\n"
            ."of the InnoDB data, fixed options of all servers. Other option files must not set these options, they are\n"
            .'commented out there.')
            ."[mysqld]\n"
            .'innodb_buffer_pool_size = '.intdiv($size, SystemInfo::MB)."M\n";

        if (! $mariadb) {
            $content .= 'innodb_buffer_pool_instances = '.$instances."\n";
        }

        if ($performanceSchema) {
            $content .= "performance_schema = OFF\n";
        }

        foreach ($fixed as [$option, $value]) {
            $content .= $option.($value === null ? '' : ' = '.$value)."\n";
        }

        return $content;
    }

    /**
     * Read the variables, the InnoDB data and the option files.
     *
     * @return array
     */
    protected function inspect(): array
    {
        if (! trim((string) shell_exec('command -v mysqld 2> /dev/null'))) {
            return ['installed' => false];
        }

        try {
            $mysql = vpsManager()->mysql()->connect();
            $variables = $mysql->query('SELECT @@innodb_buffer_pool_size AS size, @@innodb_buffer_pool_chunk_size AS chunk, '
                .'@@innodb_buffer_pool_instances AS instances, @@performance_schema AS ps, @@version AS version')->fetch_assoc();
            $data = (int) $mysql->query("SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE engine = 'InnoDB'")->fetch_row()[0];

            $mariadb = stripos($variables['version'], 'mariadb') !== false;
            $replication = $this->isReplicated($mysql, $mariadb);
            $hosts = array_column($mysql->query('SELECT DISTINCT host FROM mysql.user')->fetch_all(), 0);
            $remote = array_diff($hosts, ['localhost', '127.0.0.1', '::1']) !== [];
            $mysqlx = ! $mariadb && (int) $mysql->query("SELECT COUNT(*) FROM information_schema.plugins WHERE plugin_name = 'mysqlx' AND plugin_status = 'ACTIVE'")->fetch_row()[0] > 0;
            $fixed = static::fixedOptions($this->version($variables['version']), $mariadb, $replication, $remote, $mysqlx, static::hasHostnames($hosts));

            [$datadir, $logBin] = $mysql->query('SELECT @@datadir, @@log_bin')->fetch_row();
            $logBin = (bool) $logBin;
            $connections = [$mysql->query('SELECT @@max_connections')->fetch_row()[0], $mysql->query("SHOW GLOBAL STATUS LIKE 'Max_used_connections'")->fetch_row()[1] ?? null];
            $telescope = $mysql->query("SELECT table_schema, ROUND((data_length + index_length) / 1048576) FROM information_schema.tables WHERE table_name = 'telescope_entries' AND data_length + index_length > 268435456")->fetch_all();

            // The global values, a variable of the session keeps the value of the connect
            $select = implode(', ', array_map(fn ($option) => '@@GLOBAL.'.$option[2].' AS `'.$option[2].'`', $fixed));
            $current = $mysql->query('SELECT '.$select)->fetch_assoc();
            $fulltext = (int) $mysql->query("SELECT COUNT(DISTINCT table_schema, table_name) FROM information_schema.statistics WHERE index_type = 'FULLTEXT'")->fetch_row()[0];
        } catch (Throwable $e) {
            return ['installed' => true, 'warnings' => ['MySQL is not available: '.$e->getMessage()]];
        }

        $ram = SystemInfo::ram();
        $chunk = max((int) $variables['chunk'], 128 * SystemInfo::MB);
        // A database server alone keeps most of RAM for the buffer pool
        $dedicated = ! $this->run('pgrep -x nginx') && ! $this->run('pgrep -f "php-fpm: master"');
        [$size, $instances] = static::bufferPool($ram, $data, $chunk, $mariadb, $dedicated);

        $state = [
            'installed' => true,
            'mariadb' => $mariadb,
            'version' => $variables['version'],
            'size' => (int) $variables['size'],
            'ps' => (bool) $variables['ps'],
            'target' => $size,
            'instances' => $instances,
            'ps_off' => $ram <= self::PERFORMANCE_SCHEMA_RAM,
            'online' => false,
            'fixed' => $fixed,
            'datadir' => $datadir,
            // Old binary logs, removed when the binary log is (or will be after the restart) off
            'binlogs' => $logBin && ! in_array('disable_log_bin', array_column($fixed, 0), true) ? [] : static::binlogFiles($datadir),
            // Fixed options whose running value differs
            'differ' => array_values(array_filter($fixed, fn ($option) => (string) $current[$option[2]] !== $option[3])),
            'warnings' => [],
            'conflicts' => [],
        ];

        if ($dedicated) {
            $state['warnings'][] = 'A database server without NGINX and PHP-FPM, the buffer pool takes '.(int) round($size / max($ram, 1) * 100).' % of RAM.';
        }

        if ($connections[1] !== null && (int) $connections[1] >= (int) $connections[0]) {
            $state['warnings'][] = 'max_connections '.$connections[0].' has been reached ('.$connections[1].' connections), the applications got "Too many connections".';
        }

        foreach ($telescope as [$schema, $megabytes]) {
            $state['warnings'][] = 'Telescope of '.$schema.' has '.$megabytes.' MB (telescope_entries), prune it (telescope:prune) or turn it off in production.';
        }

        if (! $directory = $this->directory()) {
            $state['warnings'][] = 'No option files of MySQL in /etc/mysql, the buffer pool is not changed.';

            return $state;
        }

        $state['file'] = $directory.'/'.self::FILE;
        $state['content'] = $this->content($size, $instances, $state['ps_off'], $mariadb, $fixed);
        $state['written'] = is_file($state['file']) && file_get_contents($state['file']) === $state['content'];

        $keys = array_merge(
            ['innodb_buffer_pool_size'],
            $mariadb ? [] : ['innodb_buffer_pool_instances'],
            $state['ps_off'] ? ['performance_schema'] : [],
            static::replacedOptions($fixed, $this->version($variables['version']), $mariadb, $replication),
        );

        foreach ($this->optionFiles() as $path) {
            $content = (string) file_get_contents($path);
            [$disabled, $lines] = static::disableOptions($content, $keys, 'Disabled by VPS Manager (setup:run), see '.$state['file']);

            if ($lines) {
                $state['conflicts'][$path] = ['content' => $disabled, 'lines' => $lines];
            }
        }

        // Indexes built with other values do not find the short words until they are rebuilt
        $fulltextOptions = array_intersect(array_column($state['differ'], 0), ['innodb_ft_min_token_size', 'innodb_ft_enable_stopword']);

        if ($fulltext && $fulltextOptions) {
            $state['warnings'][] = 'After the restart with '.implode(', ', $fulltextOptions).' rebuild the FULLTEXT indexes of '.$fulltext.' tables (ALTER TABLE … FORCE).';
        }

        if ($replication) {
            $state['warnings'][] = 'The server replicates, its binary log and the options of the replication are kept.';
        }

        // The buffer pool is resized online in multiples of chunk × instances of the running server
        $unit = (int) $variables['chunk'] * ($mariadb ? 1 : (int) $variables['instances']);
        $state['online'] = $unit > 0 && $size % $unit === 0;

        return $state;
    }

    /**
     * Determine if the server is a replica or has replicas.
     *
     * @param  \mysqli  $mysql
     * @param  bool  $mariadb
     * @return bool
     */
    protected function isReplicated(\mysqli $mysql, bool $mariadb): bool
    {
        try {
            $replica = $mysql->query($mariadb ? 'SHOW SLAVE STATUS' : 'SELECT 1 FROM performance_schema.replication_connection_status')->num_rows > 0;
            $source = $mysql->query($mariadb ? 'SHOW SLAVE HOSTS' : 'SHOW REPLICAS')->num_rows > 0;
        } catch (Throwable $e) {
            return false;
        }

        return $replica || $source;
    }

    /**
     * Version number of the version string, e.g. 8.0.36 of 8.0.36-0ubuntu0.24.04.1.
     *
     * @param  string  $version
     * @return string
     */
    protected function version(string $version): string
    {
        return preg_match('/^\d+\.\d+\.\d+/', $version, $match) ? $match[0] : '0';
    }

    /**
     * Determine if the planned changes need a restart of MySQL.
     *
     * @return bool
     */
    protected function needsRestart(): bool
    {
        $state = $this->state();

        if (! isset($state['file'])) {
            return false;
        }

        return ($state['size'] !== $state['target'] && ! $state['online'])
            || ($state['ps_off'] && $state['ps'])
            || array_filter($state['differ'], fn ($option) => ! $option[4]);
    }

    /**
     * Current value of the setting for the table.
     *
     * @return string
     */
    public function current(): string
    {
        $state = $this->state();

        if (! $state['installed']) {
            return 'not installed';
        }

        if (! isset($state['size'])) {
            return 'unknown';
        }

        return intdiv($state['size'], SystemInfo::MB).' MB, ps='.($state['ps'] ? 'ON' : 'OFF');
    }

    /**
     * Recommended value of the setting for the table.
     *
     * @return string
     */
    public function recommended(): string
    {
        $state = $this->state();

        if (! isset($state['target'])) {
            return '-';
        }

        return intdiv($state['target'], SystemInfo::MB).' MB, ps='.($state['ps_off'] ? 'OFF' : ($state['ps'] ? 'ON' : 'OFF'));
    }

    /**
     * Changes which would be made.
     *
     * @return array
     */
    public function plan(): array
    {
        $state = $this->state();

        if (! isset($state['file'])) {
            return [];
        }

        $plan = [];

        if (! $state['written']) {
            $plan[] = 'write '.self::FILE;
        }

        foreach ($state['conflicts'] as $path => $conflict) {
            $names = array_map(fn ($line) => trim(strtok($line, '=')), $conflict['lines']);
            $names = array_unique($names);
            $plan[] = 'comment out '.(count($names) > 3 ? count($names).' options' : implode(', ', $names)).' in '.$path;
        }

        if ($state['size'] !== $state['target']) {
            $plan[] = $state['online'] ? 'resize online' : 'buffer pool after restart';
        }

        if ($state['ps_off'] && $state['ps']) {
            $plan[] = 'ps after restart';
        }

        foreach ([true => 'set online', false => 'after restart'] as $dynamic => $label) {
            if ($names = array_column(array_filter($state['differ'], fn ($option) => $option[4] === (bool) $dynamic), 2)) {
                $plan[] = implode(', ', $names).' '.$label;
            }
        }

        if ($state['binlogs']) {
            $plan[] = 'remove old binary logs ('.static::size(array_sum($state['binlogs'])).')';
        }

        if ($this->needsRestart()) {
            $plan[] = 'restart MySQL (asks)';
        }

        return $plan;
    }

    /**
     * Write the option files, resize the buffer pool online and restart MySQL when confirmed.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Closure  $confirm
     * @return bool
     */
    public function apply(OutputInterface $output, Closure $confirm): bool
    {
        $state = $this->state();
        $files = [$state['file'] => $state['content']] + array_map(fn ($conflict) => $conflict['content'], $state['conflicts']);
        $previous = [];

        foreach ($files as $path => $content) {
            $previous[$path] = is_file($path) ? file_get_contents($path) : null;

            if (! $this->write($path, $content, $output)) {
                return $this->restore($previous, $output, 'The option files of MySQL have been restored.');
            }
        }

        foreach ($state['conflicts'] as $path => $conflict) {
            $output->writeln('Commented out in '.$path.': <comment>'.implode('</comment>, <comment>', $conflict['lines']).'</comment>');
        }

        // MariaDB has no --validate-config
        if (! $state['mariadb'] && ! $this->run('mysqld --validate-config', $lines)) {
            $output->writeln('<error>mysqld --validate-config failed: '.implode(' ', $lines).'</error>');

            return $this->restore($previous, $output, 'The option files of MySQL have been restored.');
        }

        if ($state['size'] !== $state['target'] && $state['online'] && ! $this->resizeOnline($state['target'], $output)) {
            return false;
        }

        if (! $this->setOnline(array_filter($state['differ'], fn ($option) => $option[4]), $output)) {
            return false;
        }

        $this->removeBinlogs($output);

        if (! $this->needsRestart()) {
            return true;
        }

        if (! $confirm('Restart MySQL now? performance_schema and the buffer pool change only with a restart, the websites lose the database for a few seconds.', false)) {
            $output->writeln('<comment>The changes of MySQL take effect with its next restart.</comment>');

            return true;
        }

        return $this->restart($output);
    }

    /**
     * Resize the buffer pool without a restart and wait for the end of the resize.
     *
     * @param  int  $size
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return bool
     */
    protected function resizeOnline(int $size, OutputInterface $output): bool
    {
        $mysql = vpsManager()->mysql()->connect();
        $output->writeln('Resizing the buffer pool to <info>'.intdiv($size, SystemInfo::MB).' MB</info>…');

        try {
            $mysql->query('SET GLOBAL innodb_buffer_pool_size = '.$size);

            for ($i = 0; $i < 600; $i++) {
                $status = [];

                foreach ($mysql->query("SHOW GLOBAL STATUS LIKE 'Innodb_buffer_pool_resize_status%'")->fetch_all() as [$name, $value]) {
                    $status[strtolower($name)] = $value;
                }

                // The code exists since MySQL 8.0.31, 0 when no resize is running
                $code = $status['innodb_buffer_pool_resize_status_code'] ?? null;
                $text = $status['innodb_buffer_pool_resize_status'] ?? '';

                if ($code !== null ? (int) $code === 0 : ($text === '' || stripos($text, 'complete') !== false)) {
                    break;
                }

                sleep(1);
            }

            $current = (int) $mysql->query('SELECT @@innodb_buffer_pool_size')->fetch_row()[0];
        } catch (Throwable $e) {
            $output->writeln('<error>The buffer pool could not be resized: '.$e->getMessage().'</error>');

            return false;
        }

        if ($current !== $size) {
            $output->writeln('<error>The buffer pool has '.intdiv($current, SystemInfo::MB).' MB instead of '.intdiv($size, SystemInfo::MB).' MB.</error>');

            return false;
        }

        $output->writeln('The buffer pool has <info>'.intdiv($current, SystemInfo::MB).' MB</info>.');

        return true;
    }

    /**
     * Set the dynamic options without a restart and check them.
     *
     * @param  array  $options  of fixedOptions()
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return bool
     */
    protected function setOnline(array $options, OutputInterface $output): bool
    {
        $mysql = vpsManager()->mysql()->connect();

        foreach ($options as [$option, $value, $variable, $expected]) {
            try {
                $mysql->query('SET GLOBAL '.$variable.' = '.(is_numeric($expected) ? $expected : "'".$mysql->real_escape_string($value)."'"));
                $current = (string) $mysql->query('SELECT @@GLOBAL.'.$variable)->fetch_row()[0];
            } catch (Throwable $e) {
                $output->writeln('<error>'.$variable.' could not be set: '.$e->getMessage().'</error>');

                return false;
            }

            if ($current !== $expected) {
                $output->writeln('<error>'.$variable.' is '.$current.' instead of '.$expected.'.</error>');

                return false;
            }

            $output->writeln('Set <info>'.$variable.' = '.$value.'</info>.');
        }

        return true;
    }

    /**
     * Restart MySQL and check its variables.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return bool
     */
    protected function restart(OutputInterface $output): bool
    {
        $state = $this->state();
        $output->writeln('Restarting MySQL…');

        vpsManager()->mysql()->disconnect();

        if (! $this->run($this->restartCommand(), $lines)) {
            $output->writeln('<error>MySQL could not be restarted: '.implode(' ', $lines).'</error>');

            return false;
        }

        for ($i = 0; $i < 60; $i++) {
            try {
                $variables = vpsManager()->mysql()->connect()
                    ->query('SELECT @@innodb_buffer_pool_size AS size, @@performance_schema AS ps')->fetch_assoc();

                break;
            } catch (Throwable $e) {
                vpsManager()->mysql()->disconnect();
                sleep(1);
            }
        }

        if (! isset($variables)) {
            $output->writeln('<error>MySQL does not answer after the restart.</error>');

            return false;
        }

        $output->writeln('MySQL is running: buffer pool <info>'.intdiv((int) $variables['size'], SystemInfo::MB).' MB</info>, performance_schema <info>'.($variables['ps'] ? 'ON' : 'OFF').'</info>.');

        $select = implode(', ', array_map(fn ($option) => '@@GLOBAL.'.$option[2].' AS `'.$option[2].'`', $state['fixed']));
        $current = $state['fixed'] ? vpsManager()->mysql()->connect()->query('SELECT '.$select)->fetch_assoc() : [];
        $differ = array_filter($state['fixed'], fn ($option) => (string) $current[$option[2]] !== $option[3]);

        if ($differ) {
            $output->writeln('<error>MySQL has other values of '.implode(', ', array_column($differ, 2)).'.</error>');
        }

        if ((int) $variables['size'] !== $state['target'] || ($state['ps_off'] && $variables['ps']) || $differ) {
            $output->writeln('<error>MySQL does not use the new values, another option file may override them.</error>');

            return false;
        }

        $this->removeBinlogs($output);

        return true;
    }

    /**
     * Remove the old binary logs once the running server writes none, PURGE BINARY LOGS does not work then.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return void
     */
    protected function removeBinlogs(OutputInterface $output): void
    {
        $state = $this->state();

        if (! $state['binlogs'] || (int) vpsManager()->mysql()->connect()->query('SELECT @@log_bin')->fetch_row()[0] !== 0) {
            return;
        }

        $files = static::binlogFiles($state['datadir']);

        foreach (array_keys($files) as $path) {
            @unlink($path);
        }

        $output->writeln('Removed '.count($files).' old binary logs ('.static::size(array_sum($files)).').');
    }

    /**
     * Command restarting MySQL by its service.
     *
     * @return string
     */
    protected function restartCommand(): string
    {
        if (is_dir('/run/systemd/system')) {
            foreach (['mysql', 'mariadb', 'mysqld'] as $service) {
                if ($this->run('systemctl cat '.$service.'.service')) {
                    return 'systemctl restart '.$service;
                }
            }
        }

        return 'service mysql restart';
    }
}
