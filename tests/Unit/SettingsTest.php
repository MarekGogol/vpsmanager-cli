<?php

namespace Tests\Unit;

use Gogol\VpsManagerCLI\Helpers\Settings\MysqlSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\SshSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\SwappinessSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\SwapRefresh;
use Gogol\VpsManagerCLI\Helpers\Settings\SwapSetting;
use Gogol\VpsManagerCLI\Helpers\Settings\SystemInfo;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
    const MB = SystemInfo::MB;

    const GB = SystemInfo::GB;

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
            ['innodb_ft_min_token_size', 'innodb_ft_enable_stopword', 'table_open_cache', 'innodb_redo_log_capacity', 'log_error_verbosity', 'disable_log_bin'],
            $names(MysqlSetting::fixedOptions('8.0.46', false, false)),
        );
        $this->assertSame($names(MysqlSetting::fixedOptions('8.0.46', false, false)), $names(MysqlSetting::fixedOptions('9.7.2', false, false)));

        // innodb_redo_log_capacity exists since 8.0.30
        $this->assertNotContains('innodb_redo_log_capacity', $names(MysqlSetting::fixedOptions('8.0.29', false, false)));

        // A replicating server keeps its binary log
        $this->assertNotContains('disable_log_bin', $names(MysqlSetting::fixedOptions('8.0.46', false, true)));

        // MariaDB has no redo capacity, log_error_verbosity nor disable_log_bin
        $this->assertSame(
            ['innodb_ft_min_token_size', 'innodb_ft_enable_stopword', 'table_open_cache'],
            $names(MysqlSetting::fixedOptions('10.11.6', true, false)),
        );
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
