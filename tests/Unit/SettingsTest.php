<?php

namespace Tests\Unit;

use Gogol\VpsManagerCLI\Helpers\Settings\AptKeysSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\JournaldSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\MysqlSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\NginxDefaultSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\PhpFpmSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\Setting;
use Gogol\VpsManagerCLI\Helpers\Settings\SshSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\StunnelSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\SwappinessSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\SwapRefresh;
use Gogol\VpsManagerCLI\Helpers\Settings\SwapSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\SystemInfo;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
    const MB = SystemInfo::MB;

    const GB = SystemInfo::GB;

    public function test_backups_of_a_run_keep_the_original_paths(): void
    {
        $time = mktime(22, 30, 0, 10, 14, 2026);

        $this->assertSame('/var/backups/vpsmanager/20261014-223000/etc/php/8.3/fpm/pool.d/kampo.sk.conf', Setting::backupPath('/etc/php/8.3/fpm/pool.d/kampo.sk.conf', $time));
        $this->assertSame('/var/backups/vpsmanager/20261014-223000/etc/fstab', Setting::backupPath('/etc/fstab', $time));
    }

    public function test_nominal_ram_rounds_up_the_reserved_memory(): void
    {
        // MemTotal of a server with 2 GB, 4 GB and 8 GB
        $this->assertSame(2 * self::GB, SystemInfo::nominalRam(2014004 * 1024));
        $this->assertSame(4 * self::GB, SystemInfo::nominalRam(4015000 * 1024));
        $this->assertSame(8 * self::GB, SystemInfo::nominalRam(8126000 * 1024));
        $this->assertSame(2 * self::GB, SystemInfo::nominalRam(2 * self::GB));
    }

    public function test_meminfo_and_swaps_are_parsed_into_bytes(): void
    {
        $memory = SystemInfo::parseMemInfo("MemTotal:        2014004 kB\nMemAvailable:     312000 kB\nSwapTotal:       2097148 kB\nHugePages_Total:       0\n");

        $this->assertSame(2014004 * 1024, $memory['MemTotal']);
        $this->assertSame(312000 * 1024, $memory['MemAvailable']);
        $this->assertSame(0, $memory['HugePages_Total']);

        $swaps = SystemInfo::parseSwaps("Filename\t\t\t\tType\t\tSize\t\tUsed\t\tPriority\n/swapfile                               file\t\t2097148\t\t1468000\t\t-2\n/dev/vda3 partition 1048572 0 -3\n");

        $this->assertSame([
            ['path' => '/swapfile', 'type' => 'file', 'size' => 2097148 * 1024, 'used' => 1468000 * 1024],
            ['path' => '/dev/vda3', 'type' => 'partition', 'size' => 1048572 * 1024, 'used' => 0],
        ], $swaps);
    }

    public function test_swap_size_follows_ram(): void
    {
        $this->assertSame(2 * self::GB, SwapSetting::recommendedSize(1 * self::GB));
        $this->assertSame(3 * self::GB, SwapSetting::recommendedSize((int) (1.5 * self::GB)));
        $this->assertSame(2 * self::GB, SwapSetting::recommendedSize(2 * self::GB));
        $this->assertSame(8 * self::GB, SwapSetting::recommendedSize(8 * self::GB));
        $this->assertSame(6 * self::GB, SwapSetting::recommendedSize(12 * self::GB));
        $this->assertSame(32 * self::GB, SwapSetting::recommendedSize(64 * self::GB));
        $this->assertSame(4 * self::GB, SwapSetting::recommendedSize(128 * self::GB));
    }

    public function test_swap_size_is_limited_by_the_disk(): void
    {
        // 10 % of the disk at most
        $this->assertSame(2560 * self::MB, SwapSetting::limitByDisk(4 * self::GB, 25 * self::GB, 15 * self::GB));

        // 20 % of the disk stays free
        $this->assertSame(1 * self::GB, SwapSetting::limitByDisk(4 * self::GB, 100 * self::GB, 21 * self::GB));

        // The resized swap file frees its own size
        $this->assertSame(3 * self::GB, SwapSetting::limitByDisk(3 * self::GB, 100 * self::GB, 21 * self::GB, 2 * self::GB));

        // Less than 1 GB is no swap
        $this->assertSame(0, SwapSetting::limitByDisk(4 * self::GB, 8 * self::GB, 6 * self::GB));
        $this->assertSame(0, SwapSetting::limitByDisk(4 * self::GB, 100 * self::GB, 10 * self::GB));

        // Rounded down to 128 MB
        $this->assertSame(1280 * self::MB, SwapSetting::limitByDisk(4 * self::GB, 13 * self::GB, 13 * self::GB));
    }

    public function test_swap_within_the_tolerance_is_kept(): void
    {
        $this->assertTrue(SwapSetting::needsChange(2 * self::GB, 4 * self::GB));
        $this->assertTrue(SwapSetting::needsChange(0, 2 * self::GB));
        $this->assertFalse(SwapSetting::needsChange(2 * self::GB, (int) (2.5 * self::GB)));
        $this->assertFalse(SwapSetting::needsChange(4 * self::GB, 0));
    }

    public function test_fstab_has_exactly_one_line_of_the_swap_file(): void
    {
        $fstab = "UUID=abc / ext4 defaults 0 1\n";

        $this->assertSame($fstab."/swapfile none swap sw 0 0\n", SwapSetting::fstabWithSwap($fstab, '/swapfile'));

        // An existing line is kept as it is
        $existing = $fstab."/swapfile swap swap defaults 0 0\n";
        $this->assertSame($existing, SwapSetting::fstabWithSwap($existing, '/swapfile'));

        // Duplicates are removed, comments are kept
        $this->assertSame(
            $fstab."# /swapfile none swap sw 0 0\n/swapfile none swap sw 0 0\n",
            SwapSetting::fstabWithSwap($fstab."# /swapfile none swap sw 0 0\n/swapfile none swap sw 0 0\n/swapfile none swap sw 0 0\n", '/swapfile'),
        );

        $this->assertSame("/swapfile none swap sw 0 0\n", SwapSetting::fstabWithSwap('', '/swapfile'));
    }

    public function test_swappiness_of_other_sysctl_files_is_commented_out(): void
    {
        $content = "net.ipv4.ip_forward = 1\nvm.swappiness=60\n-vm/swappiness = 30\n# vm.swappiness = 1\n";

        $this->assertSame(
            "net.ipv4.ip_forward = 1\n# off: vm.swappiness=60\n# off: -vm/swappiness = 30\n# vm.swappiness = 1\n",
            SwappinessSetting::disableKey($content, 'vm.swappiness', 'off'),
        );
    }

    public function test_buffer_pool_follows_ram_of_a_shared_server(): void
    {
        $this->assertSame([512 * self::MB, 1], MysqlSetting::bufferPool(2 * self::GB, 10 * self::GB));
        $this->assertSame([1280 * self::MB, 1], MysqlSetting::bufferPool(4 * self::GB, 10 * self::GB));
        $this->assertSame([16 * self::GB, 8], MysqlSetting::bufferPool(32 * self::GB, 100 * self::GB));

        // 40 % of 16 GB is 6.4 GB, rounded to chunk × 6 instances (768 MB)
        $this->assertSame([6912 * self::MB, 6], MysqlSetting::bufferPool(16 * self::GB, 100 * self::GB));

        // MariaDB rounds by the chunk only
        $this->assertSame([16 * self::GB, 1], MysqlSetting::bufferPool(32 * self::GB, 100 * self::GB, 128 * self::MB, true));
    }

    public function test_buffer_pool_of_a_dedicated_database_server(): void
    {
        // adb1 and sdb1: 3.5 GB of RAM, 3.3 GB and 1.9 GB of data
        $this->assertSame([2 * self::GB, 2], MysqlSetting::bufferPool((int) (3.5 * self::GB), (int) (3.3 * self::GB), 128 * self::MB, false, true));
        $this->assertSame([2 * self::GB, 2], MysqlSetting::bufferPool((int) (3.5 * self::GB), (int) (1.9 * self::GB), 128 * self::MB, false, true));

        $this->assertSame([11 * self::GB, 8], MysqlSetting::bufferPool(16 * self::GB, 100 * self::GB, 128 * self::MB, false, true));
        $this->assertSame([48 * self::GB, 8], MysqlSetting::bufferPool(64 * self::GB, 100 * self::GB, 128 * self::MB, false, true));
    }

    public function test_binary_logs_of_the_data_directory(): void
    {
        $directory = sys_get_temp_dir().'/vpsmanager-binlogs-'.getmypid();
        mkdir($directory);

        foreach (['binlog.000214', 'binlog.000215', 'binlog.index', 'binlog.000215.tmp', 'ibdata1', 'undo_001'] as $file) {
            file_put_contents($directory.'/'.$file, 'x');
        }

        $this->assertSame(
            [$directory.'/binlog.000214', $directory.'/binlog.000215', $directory.'/binlog.index'],
            array_keys(MysqlSetting::binlogFiles($directory.'/')),
        );

        array_map('unlink', glob($directory.'/*'));
        rmdir($directory);
    }

    public function test_names_are_resolved_for_application_users_of_localhost(): void
    {
        // adb1, sdb1: the applications connect from the LAN
        $this->assertFalse(MysqlSetting::needsResolving([['root', 'localhost'], ['api', '192.168.1.%'], ['debian-sys-maint', 'localhost'], ['mysql.sys', 'localhost']]));

        // qualit, ms: Laravel connects to 127.0.0.1 as alkoholonline_sk@localhost, which needs the resolved name
        $this->assertTrue(MysqlSetting::needsResolving([['root', 'localhost'], ['alkoholonline_sk', 'localhost']]));

        // A user of a host name
        $this->assertTrue(MysqlSetting::needsResolving([['api', 'app.example.com']]));

        $names = fn (array $options) => array_column($options, 0);
        $this->assertContains('skip_name_resolve', $names(MysqlSetting::fixedOptions('8.4.4', false, false, true, true, false)));
        $this->assertNotContains('skip_name_resolve', $names(MysqlSetting::fixedOptions('8.4.4', false, false, true, true, true)));
    }

    public function test_buffer_pool_is_limited_by_the_data(): void
    {
        // 1.5 × 1.4 GB of data
        $this->assertSame([2 * self::GB, 2], MysqlSetting::bufferPool(16 * self::GB, (int) (1.4 * self::GB)));
        $this->assertSame([640 * self::MB, 1], MysqlSetting::bufferPool(8 * self::GB, 400 * self::MB));

        // 128 MB at least
        $this->assertSame([128 * self::MB, 1], MysqlSetting::bufferPool(8 * self::GB, 10 * self::MB));
        $this->assertSame([128 * self::MB, 1], MysqlSetting::bufferPool(8 * self::GB, 0));
    }

    public function test_fixed_options_follow_the_version_of_the_server(): void
    {
        $names = fn (array $options) => array_column($options, 0);

        // MySQL 8.0.46 of wms, 9.7 of ms
        $this->assertSame(
            ['innodb_ft_min_token_size', 'innodb_ft_enable_stopword', 'table_open_cache', 'innodb_redo_log_capacity', 'innodb_flush_method', 'log_error_verbosity', 'disable_log_bin', 'bind_address'],
            $names(MysqlSetting::fixedOptions('8.0.46', false, false)),
        );

        // O_DIRECT is the default since 8.4
        $this->assertSame(
            array_values(array_diff($names(MysqlSetting::fixedOptions('8.0.46', false, false)), ['innodb_flush_method'])),
            $names(MysqlSetting::fixedOptions('9.7.2', false, false)),
        );

        // innodb_redo_log_capacity exists since 8.0.30
        $this->assertNotContains('innodb_redo_log_capacity', $names(MysqlSetting::fixedOptions('8.0.29', false, false)));

        // A replicating server keeps its binary log
        $this->assertNotContains('disable_log_bin', $names(MysqlSetting::fixedOptions('8.0.46', false, true)));

        // MariaDB has no redo capacity, log_error_verbosity nor disable_log_bin
        $this->assertSame(
            ['innodb_ft_min_token_size', 'innodb_ft_enable_stopword', 'table_open_cache', 'bind_address'],
            $names(MysqlSetting::fixedOptions('10.11.6', true, false)),
        );
    }

    public function test_mysql_listens_on_localhost_without_remote_users(): void
    {
        $names = fn (array $options) => array_column($options, 0);

        $this->assertContains('bind_address', $names(MysqlSetting::fixedOptions('9.7.2', false, false, false, true)));
        $this->assertContains('mysqlx_bind_address', $names(MysqlSetting::fixedOptions('9.7.2', false, false, false, true)));
        $this->assertNotContains('mysqlx_bind_address', $names(MysqlSetting::fixedOptions('9.7.2', false, false, false, false)));

        // A database server of the web servers in the LAN (users @192.168.1.%) keeps its address
        $this->assertNotContains('bind_address', $names(MysqlSetting::fixedOptions('8.0.45', false, false, true, true)));
        $this->assertNotContains('bind_address', $names(MysqlSetting::fixedOptions('8.0.45', false, true, false, true)));

        // bind-address of mysqld.cnf is commented out, the managed file sets it
        [, $lines] = MysqlSetting::disableOptions("[mysqld]\nbind-address = 0.0.0.0\n", array_column(MysqlSetting::fixedOptions('8.0.45', false, false), 0), 'off');
        $this->assertSame(['bind-address = 0.0.0.0'], $lines);
    }

    public function test_obsolete_options_are_commented_out(): void
    {
        $fixed = MysqlSetting::fixedOptions('8.0.46', false, false);
        $keys = MysqlSetting::replacedOptions($fixed, '8.0.46', false, false);

        foreach (['innodb_log_file_size', 'innodb_log_files_in_group', 'binlog_expire_logs_seconds', 'replica_parallel_workers', 'log_bin', 'table_open_cache', 'log_error_verbosity'] as $key) {
            $this->assertContains($key, $keys);
        }

        $replicated = MysqlSetting::replacedOptions(MysqlSetting::fixedOptions('8.0.46', false, true), '8.0.46', false, true);
        $this->assertNotContains('binlog_expire_logs_seconds', $replicated);
        $this->assertNotContains('replica_parallel_workers', $replicated);

        // The block of ms and wms
        $content = "[mysqld]\ninnodb_ft_min_token_size=2\ninnodb_log_file_size=250M # 25% of innodb_buffer_pool_size\ntable_open_cache=16000\n"
            ."log-error-verbosity=1\nreplica_parallel_workers=1\n\n# Binlogs (only needed in replica mode)\nbinlog_expire_logs_seconds=259200\ndisable_log_bin=ON\n";

        [, $lines] = MysqlSetting::disableOptions($content, $keys, 'off');

        $this->assertSame([
            'innodb_ft_min_token_size=2',
            'innodb_log_file_size=250M # 25% of innodb_buffer_pool_size',
            'table_open_cache=16000',
            'log-error-verbosity=1',
            'replica_parallel_workers=1',
            'binlog_expire_logs_seconds=259200',
            'disable_log_bin=ON',
        ], $lines);
    }

    public function test_options_of_the_server_sections_are_commented_out(): void
    {
        $content = implode("\n", [
            '!includedir /etc/mysql/conf.d/',
            '!includedir /etc/mysql/mysql.conf.d/',
            '[mysqld]',
            'innodb_buffer_pool_size=1G',
            'innodb-log-file-size = 256M',
            '[client]',
            'performance_schema=ON',
            '[mysqld-8.0]',
            'loose-performance-schema = ON',
            '',
        ]);

        [$disabled, $lines] = MysqlSetting::disableOptions($content, ['innodb_buffer_pool_size', 'performance_schema'], 'off');

        $this->assertSame(['innodb_buffer_pool_size=1G', 'loose-performance-schema = ON'], $lines);
        $this->assertStringContainsString("[mysqld]\n# off: innodb_buffer_pool_size=1G\ninnodb-log-file-size = 256M\n", $disabled);
        $this->assertStringContainsString("[client]\nperformance_schema=ON\n", $disabled);
        $this->assertStringContainsString("# off: loose-performance-schema = ON\n", $disabled);

        $this->assertTrue(MysqlSetting::hasOption($content, 'innodb_log_file_size'));
        $this->assertFalse(MysqlSetting::hasOption("[mysqld]\n# innodb_log_file_size = 1G\n", 'innodb_log_file_size'));
    }

    public function test_sshd_takes_the_first_value_of_the_included_files(): void
    {
        $main = "Include /etc/ssh/sshd_config.d/*.conf\n\nPort 22\nPasswordAuthentication yes\nKbdInteractiveAuthentication no\n";
        $cloudInit = ['/etc/ssh/sshd_config.d/50-cloud-init.conf' => "PasswordAuthentication yes\n"];

        $this->assertSame(
            ['passwordauthentication' => 'yes', 'kbdinteractiveauthentication' => 'no'],
            SshSetting::effective($main, $cloudInit),
        );

        $managed = ['/etc/ssh/sshd_config.d/00-vpsmanager.conf' => "PasswordAuthentication no\nKbdInteractiveAuthentication no\nPermitRootLogin prohibit-password\n"];

        $this->assertSame(
            ['passwordauthentication' => 'no', 'kbdinteractiveauthentication' => 'no', 'permitrootlogin' => 'prohibit-password'],
            SshSetting::effective($main, $cloudInit + $managed),
        );
    }

    public function test_sshd_options_before_the_include_or_in_match_win_or_are_skipped(): void
    {
        $managed = ['/etc/ssh/sshd_config.d/00-vpsmanager.conf' => "PasswordAuthentication no\n"];

        // An option before the Include wins over the managed file
        $this->assertSame(
            ['passwordauthentication' => 'yes'],
            SshSetting::effective("PasswordAuthentication yes\nInclude /etc/ssh/sshd_config.d/*.conf\n", $managed),
        );

        // Options of a Match block are not global, without Include the managed file is not read
        $this->assertSame(
            ['permitrootlogin' => 'prohibit-password'],
            SshSetting::effective("PermitRootLogin without-password\nMatch User deploy\n    PasswordAuthentication yes\n", $managed),
        );
    }

    public function test_sshd_config_gets_the_include_once(): void
    {
        $this->assertSame(SshSetting::INCLUDE."\n\nPort 22\n", SshSetting::withInclude("Port 22\n"));

        $main = "# comment\nInclude /etc/ssh/sshd_config.d/*.conf\nPort 22\n";
        $this->assertSame($main, SshSetting::withInclude($main));
    }

    public function test_stricter_root_login_is_kept(): void
    {
        $this->assertSame('prohibit-password', SshSetting::targets('yes')['permitrootlogin']);
        $this->assertSame('prohibit-password', SshSetting::targets(null)['permitrootlogin']);
        $this->assertSame('no', SshSetting::targets('no')['permitrootlogin']);
        $this->assertSame('forced-commands-only', SshSetting::targets('forced-commands-only')['permitrootlogin']);

        $this->assertSame(
            ['permitrootlogin' => 'prohibit-password', 'kbdinteractiveauthentication' => 'no'],
            SshSetting::normalize(['challengeresponseauthentication' => 'no', 'permitrootlogin' => 'without-password', 'port' => '22']),
        );
    }

    public function test_keys_of_authorized_keys_are_found(): void
    {
        $this->assertTrue(SshSetting::isKey('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI root@mac'));
        $this->assertTrue(SshSetting::isKey('from="192.168.1.0/24" ssh-rsa AAAAB3NzaC1yc2E root@server'));
        $this->assertTrue(SshSetting::isKey('ecdsa-sha2-nistp256 AAAAE2VjZHNh'));
        $this->assertTrue(SshSetting::isKey('sk-ssh-ed25519@openssh.com AAAAGnNr'));
        $this->assertFalse(SshSetting::isKey('# ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI'));
        $this->assertFalse(SshSetting::isKey(''));
    }

    public function test_client_tunnels_of_stunnel_accept_on_localhost_only(): void
    {
        // /etc/stunnel/stunnel.conf of qualit, with the trailing spaces
        $content = "[samba445] \naccept = 445 \nclient = yes \nconnect = 33643.s43.wedos.net:446\n[samba139]\naccept = 139 \nclient = yes \nconnect = 33643.s43.wedos.net:140\n";

        [$rewritten, $changes] = StunnelSetting::rewrite($content);

        $this->assertSame(
            "debug = warning\npid = /run/stunnel4/stunnel.pid\n\n[samba445] \naccept = 127.0.0.1:445\nclient = yes \nconnect = 33643.s43.wedos.net:446\n[samba139]\naccept = 127.0.0.1:139\nclient = yes \nconnect = 33643.s43.wedos.net:140\n",
            $rewritten,
        );
        $this->assertSame(['samba445 accept 445 -> 127.0.0.1:445', 'samba139 accept 139 -> 127.0.0.1:139', 'debug notice -> warning', 'pid file'], $changes);

        // A second run changes nothing
        $this->assertSame([$rewritten, []], StunnelSetting::rewrite($rewritten));
    }

    public function test_server_tunnels_and_the_debug_level_of_stunnel(): void
    {
        // A tunnel of the server mode stays public, debug 7 is lowered
        [$rewritten, $changes] = StunnelSetting::rewrite("pid = /run/s.pid\ndebug = 7\n[https]\naccept = 0.0.0.0:443\nconnect = 80\n");

        $this->assertSame("pid = /run/s.pid\ndebug = warning\n[https]\naccept = 0.0.0.0:443\nconnect = 80\n", $rewritten);
        $this->assertSame(['debug 7 -> warning'], $changes);

        $this->assertSame([], StunnelSetting::rewrite("debug = 4\npid = /run/s.pid\n[smb]\nclient = yes\naccept = localhost:445\n")[1]);

        $this->assertSame('/run/stunnel4/backup.pid', StunnelSetting::pidPath('/etc/stunnel/backup.conf'));
        $this->assertTrue(StunnelSetting::isLocal('127.0.0.1:445'));
        $this->assertTrue(StunnelSetting::isLocal('[::1]:445'));
        $this->assertFalse(StunnelSetting::isLocal('445'));
        $this->assertFalse(StunnelSetting::isLocal('0.0.0.0:445'));
        $this->assertFalse(StunnelSetting::isLocal(':::445'));
    }

    public function test_size_of_the_journal_is_parsed(): void
    {
        $this->assertSame(520 * self::MB, JournaldSetting::parseUsage('Archived and active journals take up 520.0M in the file system.'));
        $this->assertSame((int) (1.5 * self::GB), JournaldSetting::parseUsage('Archived and active journals take up 1.5G in the file system.'));
        $this->assertSame(0, JournaldSetting::parseUsage('No journal files were found.'));
    }

    public function test_small_pools_run_on_demand_and_every_pool_restarts_its_processes(): void
    {
        // A pool of the stub before setup:run
        $pool = "[qualit.sk]\nlisten = /run/php/php8.3-fpm-qualit.sk.sock\npm = dynamic\npm.max_children = 5\npm.start_servers = 2\n;pm.process_idle_timeout = 10s;\n;pm.max_requests = 500\n";

        [$content, $changes] = PhpFpmSetting::tune($pool);

        $this->assertSame(['pm.max_requests 500', 'ondemand'], $changes);
        $this->assertSame('ondemand', PhpFpmSetting::directive($content, 'pm'));
        $this->assertSame('10s', PhpFpmSetting::directive($content, 'pm.process_idle_timeout'));
        $this->assertSame('500', PhpFpmSetting::directive($content, 'pm.max_requests'));
        $this->assertSame('2', PhpFpmSetting::directive($content, 'pm.start_servers'));
        $this->assertSame([$content, []], PhpFpmSetting::tune($content));

        // A busy pool stays dynamic, a missing pm.max_requests is added after pm.max_children
        [$content, $changes] = PhpFpmSetting::tune("[alkoholonline.sk]\npm = dynamic\npm.max_children = 30\npm.start_servers = 12\n");

        $this->assertSame(['pm.max_requests 500'], $changes);
        $this->assertSame("[alkoholonline.sk]\npm = dynamic\npm.max_children = 30\npm.max_requests = 500\npm.start_servers = 12\n", $content);

        // An own value is kept
        $this->assertSame([], PhpFpmSetting::tune("pm = static\npm.max_children = 4\npm.max_requests = 1000\n")[1]);
    }

    public function test_stub_of_a_new_pool_is_tuned_already(): void
    {
        $this->assertSame([], PhpFpmSetting::tune((string) file_get_contents(__DIR__.'/../../src/Stub/php-pool.conf'))[1]);
    }

    public function test_expired_keys_of_apt_are_found(): void
    {
        // gpg --show-keys --with-colons of the old key of MySQL and of a keyring with two keys
        $colons = implode("\n", [
            'pub:e:4096:1:B7B3B788A8D3785C:1698019200:1761091200::-:::sc::::::23::0:',
            'fpr:::::::::BCA43417C3B485DD128EC6D4B7B3B788A8D3785C:',
            'uid:e::::1698019200::0D2A::MySQL Release Engineering <mysql-build@oss.oracle.com>::::::::::0:',
            'sub:e:4096:1:C2A3B2D4E5F60718:1698019200:1761091200:::::e::::::23:',
            'fpr:::::::::1111111111111111111111111111111111111111:',
            'pub:-:3072:1:B188E2B695BD4743:1552910400:1833148800::-:::scSC::::::23::0:',
            'fpr:::::::::15058500A0235D97F5D10063B188E2B695BD4743:',
            'uid:-::::1552910400::1C2B::DEB.SURY.ORG Automatic Signing Key <deb@sury.org>::::::::::0:',
        ]);

        $keys = AptKeysSetting::parseKeys($colons, mktime(0, 0, 0, 10, 14, 2026));

        $this->assertCount(2, $keys);
        $this->assertSame('BCA43417C3B485DD128EC6D4B7B3B788A8D3785C', $keys[0]['fingerprint']);
        $this->assertSame('MySQL Release Engineering <mysql-build@oss.oracle.com>', $keys[0]['uid']);
        $this->assertTrue($keys[0]['expired']);
        $this->assertSame('15058500A0235D97F5D10063B188E2B695BD4743', $keys[1]['fingerprint']);
        $this->assertFalse($keys[1]['expired']);

        // Both are keys the setting renews
        $this->assertArrayHasKey($keys[0]['fingerprint'], AptKeysSetting::KNOWN);
        $this->assertArrayHasKey($keys[1]['fingerprint'], AptKeysSetting::KNOWN);
    }

    public function test_keyrings_of_the_sources_and_the_end_of_life(): void
    {
        $this->assertSame(
            ['/usr/share/keyrings/mysql-apt-config.gpg', '/usr/share/keyrings/deb.sury.org-php.gpg', '/etc/apt/keyrings/openvpn.asc'],
            AptKeysSetting::keyringsOf([
                "deb [signed-by=/usr/share/keyrings/mysql-apt-config.gpg] http://repo.mysql.com/apt/debian/ bookworm mysql-8.4-lts\n"
                ."deb-src [signed-by=/usr/share/keyrings/mysql-apt-config.gpg] http://repo.mysql.com/apt/debian/ bookworm mysql-8.4-lts\n",
                "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ bookworm main\n",
                "Types: deb\nURIs: https://build.openvpn.net/debian/openvpn/stable\nSigned-By: /etc/apt/keyrings/openvpn.asc\n",
            ]),
        );

        $this->assertSame('2023-05-31', AptKeysSetting::endOfLife('ubuntu', '18.04'));
        $this->assertSame('2028-06-30', AptKeysSetting::endOfLife('debian', '12'));
        $this->assertNull(AptKeysSetting::endOfLife('alpine', '3.20'));
    }

    public function test_default_server_refuses_tls_of_unknown_names(): void
    {
        // sites-available/default of the package of Debian and Ubuntu
        $package = "server {\n\tlisten 80 default_server;\n\tlisten [::]:80 default_server;\n\n\t# SSL configuration\n\t#\n\t# listen 443 ssl default_server;\n\t# listen [::]:443 ssl default_server;\n\t#\n\t# Self signed certs generated by the ssl-cert package\n\t# Don't use them in a production server!\n\t#\n\t# include snippets/snakeoil.conf;\n\n\troot /var/www/html;\n}\n";

        $rejected = NginxDefaultSetting::content($package, true);

        $this->assertStringContainsString("\tlisten 443 ssl default_server;\n\tlisten [::]:443 ssl default_server;\n\tssl_reject_handshake on;\n", $rejected);
        $this->assertStringContainsString("\t# include snippets/snakeoil.conf;", $rejected);
        $this->assertSame('reject', NginxDefaultSetting::mode($rejected));
        $this->assertSame($rejected, NginxDefaultSetting::content($rejected, true));

        // NGINX before 1.19.4 keeps the snakeoil certificate
        $snakeoil = NginxDefaultSetting::content($package, false);
        $this->assertStringContainsString("\tinclude snippets/snakeoil.conf;", $snakeoil);
        $this->assertSame('self-signed', NginxDefaultSetting::mode($snakeoil));
        $this->assertSame('first website', NginxDefaultSetting::mode($package));

        // ms: the snakeoil certificate turned on by the former install is replaced
        $ms = "server {\n\tlisten 80 default_server;\n\tlisten [::]:80 default_server;\n\tlisten 443 ssl default_server;\n\tlisten [::]:443 ssl default_server;\n\tinclude snippets/snakeoil.conf;\n\troot /var/www/html;\n}\n";
        $this->assertSame("server {\n\tlisten 80 default_server;\n\tlisten [::]:80 default_server;\n\tlisten 443 ssl default_server;\n\tlisten [::]:443 ssl default_server;\n\tssl_reject_handshake on;\n\troot /var/www/html;\n}\n", NginxDefaultSetting::content($ms, true));

        // A default server without the lines of 443 gets them
        $this->assertStringContainsString("listen [::]:80 default_server;\n    listen 443 ssl default_server;\n    listen [::]:443 ssl default_server;\n    ssl_reject_handshake on;", NginxDefaultSetting::content("server {\n    listen 80 default_server;\n    listen [::]:80 default_server;\n}\n", true));

        $this->assertTrue(NginxDefaultSetting::rejects('1.22.1'));
        $this->assertTrue(NginxDefaultSetting::rejects('1.19.4'));
        $this->assertFalse(NginxDefaultSetting::rejects('1.18.0'));
        $this->assertFalse(NginxDefaultSetting::rejects(null));
    }

    public function test_swap_refresh_keeps_enough_free_ram(): void
    {
        $this->assertStringStartsWith('only', SwapRefresh::reasonToSkip(2 * self::GB, 1 * self::GB, 50 * self::MB));

        // 1.4 GB of the swap into 1.5 GB of free RAM would leave almost nothing
        $this->assertNotNull(SwapRefresh::reasonToSkip(2 * self::GB, (int) (1.5 * self::GB), (int) (1.4 * self::GB)));

        $this->assertNull(SwapRefresh::reasonToSkip(2 * self::GB, (int) (1.5 * self::GB), 500 * self::MB));
        $this->assertNotNull(SwapRefresh::reasonToSkip(2 * self::GB, (int) (1.5 * self::GB), 1 * self::GB));
    }

    public function test_swap_refresh_turns_only_the_swaps_of_fstab(): void
    {
        $swaps = [
            ['path' => '/swapfile', 'type' => 'file', 'size' => 0, 'used' => 0],
            ['path' => '/swap-temporary', 'type' => 'file', 'size' => 0, 'used' => 0],
        ];

        $fstab = "UUID=abc / ext4 defaults 0 1\n/swapfile none swap sw 0 0\n# /swap-temporary none swap sw 0 0\n";

        $this->assertSame(['/swapfile'], SwapRefresh::fstabSwaps($swaps, $fstab));
    }
}
