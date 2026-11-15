<?php

namespace Tests\Unit;

use Carbon\Carbon;
use Gogol\VpsManagerCLI\Command\Backup\BackupListCommand;
use Gogol\VpsManagerCLI\Command\Backup\BackupSetupCommand;
use Gogol\VpsManagerCLI\Helpers\Backup;
use Gogol\VpsManagerCLI\Helpers\Restic;
use PHPUnit\Framework\TestCase;

class BackupTest extends TestCase
{
    public function test_repository_of_a_local_directory(): void
    {
        $config = ['backup_repository_type' => 'local', 'backup_local_path' => '/var/vpsmanager_backups/restic'];

        $this->assertSame('/var/vpsmanager_backups/restic', Restic::repository($config));
    }

    public function test_repository_of_an_sftp_server(): void
    {
        $config = [
            'backup_repository_type' => 'sftp',
            'backup_sftp_host' => 'u1-sub1.your-storagebox.de',
            'backup_sftp_user' => 'u1-sub1',
            'backup_sftp_path' => 'restic',
        ];

        $this->assertSame('sftp:u1-sub1@u1-sub1.your-storagebox.de:restic', Restic::repository($config));
    }

    public function test_sftp_command_sets_the_port_and_the_key(): void
    {
        $command = Restic::sftpCommand([
            'backup_sftp_host' => 'u1-sub1.your-storagebox.de',
            'backup_sftp_user' => 'u1-sub1',
            'backup_sftp_port' => 23,
            'backup_sftp_key' => '/root/.ssh/storagebox_ed25519',
        ]);

        $this->assertStringStartsWith('ssh -p 23 -i /root/.ssh/storagebox_ed25519 ', $command);
        $this->assertStringContainsString('-o BatchMode=yes', $command);
        $this->assertStringEndsWith('u1-sub1@u1-sub1.your-storagebox.de -s sftp', $command);
    }

    public function test_old_configuration_of_tar_backups_is_not_configured(): void
    {
        // A server with the old tar.gz backups after an update
        $this->assertFalse(Restic::isConfigured(['backup_path' => '/mnt/disk_backup/qualit', 'remote_backups' => false]));

        // A repository without its password
        $this->assertFalse(Restic::isConfigured(['backup_repository_type' => 'local', 'backup_local_path' => '/backups']));

        $this->assertTrue(Restic::isConfigured(['backup_repository_type' => 'local', 'backup_local_path' => '/backups', 'backup_password' => 'secret']));
    }

    public function test_supported_versions_of_restic(): void
    {
        $this->assertFalse(Restic::isSupportedVersion(null));
        $this->assertFalse(Restic::isSupportedVersion('0.14.0'));
        $this->assertFalse(Restic::isSupportedVersion('0.16.5'));
        $this->assertTrue(Restic::isSupportedVersion('0.17.0'));
        $this->assertTrue(Restic::isSupportedVersion(Restic::VERSION));
    }

    public function test_retention_arguments_of_restic_forget(): void
    {
        $this->assertSame(
            ['--keep-within', '1d', '--keep-daily', '7', '--keep-weekly', '4', '--keep-monthly', '6'],
            Restic::keepArguments(Backup::KEEP_DATABASES),
        );

        $this->assertSame(['--keep-daily', '7'], Restic::keepArguments(['daily' => 7, 'weekly' => 0, 'monthly' => null]));
    }

    public function test_directory_entries_keep_their_excludes(): void
    {
        $entries = Restic::directoryEntries('/etc/nginx/;/etc/mysql --exclude="*/debian.cnf"; ;/var/spool/cron/crontabs');

        $this->assertSame([
            ['path' => '/etc/nginx', 'excludes' => []],
            ['path' => '/etc/mysql', 'excludes' => ['*/debian.cnf']],
            ['path' => '/var/spool/cron/crontabs', 'excludes' => []],
        ], $entries);
    }

    public function test_website_excludes(): void
    {
        $ignore = "# big subdomain\nsub/subdomain1/\n\nweb/storage/app/huge.zip\n";

        $excludes = Restic::websiteExcludes('/var/www/example.com/data/', $ignore);

        $this->assertContains('node_modules', $excludes);
        $this->assertContains('vendor', $excludes);
        $this->assertContains('/var/www/example.com/data/.npm', $excludes);
        $this->assertContains('/var/www/example.com/data/sub/subdomain1', $excludes);
        $this->assertContains('/var/www/example.com/data/web/storage/app/huge.zip', $excludes);
        $this->assertNotContains('/var/www/example.com/data/# big subdomain', $excludes);
    }

    public function test_snapshots_are_described_by_type_and_name(): void
    {
        $this->assertSame(['databases', 'shop_sk'], Backup::describeSnapshot(['tags' => ['databases'], 'paths' => ['/databases/shop_sk.sql']]));
        $this->assertSame(['www', 'example.com'], Backup::describeSnapshot(['tags' => ['www'], 'paths' => ['/var/www/example.com/data']], '/var/www/'));
        $this->assertSame(['www', 'old.sk'], Backup::describeSnapshot(['tags' => ['www'], 'paths' => ['/var/www/old.sk']]));
        $this->assertSame(['dirs', '/etc/nginx /etc/php'], Backup::describeSnapshot(['tags' => ['dirs'], 'paths' => ['/etc/nginx', '/etc/php']]));
    }

    public function test_snapshots_are_grouped_newest_last(): void
    {
        $groups = BackupListCommand::groupSnapshots([
            ['id' => 'b', 'time' => '2026-11-15T12:00:00+01:00', 'tags' => ['databases'], 'paths' => ['/databases/shop.sql']],
            ['id' => 'a', 'time' => '2026-11-14T12:00:00+01:00', 'tags' => ['databases'], 'paths' => ['/databases/shop.sql']],
            ['id' => 'c', 'time' => '2026-11-15T04:00:00+01:00', 'tags' => ['www'], 'paths' => ['/var/www/shop.sk/data']],
        ], '/var/www');

        $this->assertSame(['a', 'b'], array_column($groups['databases']['shop'], 'id'));
        $this->assertSame(['c'], array_column($groups['www']['shop.sk'], 'id'));
    }

    public function test_old_tar_backups_are_found_by_their_dated_directories(): void
    {
        $path = sys_get_temp_dir().'/vpsmanager-backup-test-'.bin2hex(random_bytes(4));

        foreach (['local/databases/2026-11-15_12-00-00', 'local/www/2026-11-09_04-00-00', 'local/www/keep-me', 'local/restic'] as $directory) {
            mkdir($path.'/'.$directory, 0700, true);
        }

        try {
            $this->assertSame([
                $path.'/local/databases/2026-11-15_12-00-00',
                $path.'/local/www/2026-11-09_04-00-00',
            ], BackupSetupCommand::oldBackupDirectories($path));
        } finally {
            exec('rm -rf '.escapeshellarg($path));
        }
    }

    public function test_lock_held_by_the_maintenance_is_seen_by_the_backups(): void
    {
        $path = sys_get_temp_dir().'/vpsmanager-maintenance-test-'.bin2hex(random_bytes(4)).'.lock';

        try {
            $this->assertFalse(Backup::isLocked($path));

            $lock = Backup::lockMaintenance($path);

            $this->assertTrue(Backup::isLocked($path));

            fclose($lock);

            $this->assertFalse(Backup::isLocked($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_weekly_check_reads_every_part_in_twenty_weeks(): void
    {
        $start = Carbon::parse('2026-11-15 05:30', 'Europe/Bratislava');
        $parts = [];

        // 20 Sundays in a row, over the end of the year
        for ($week = 0; $week < 20; $week++) {
            $parts[] = Backup::readDataSubset($start->copy()->addWeeks($week));
        }

        natsort($parts);

        $this->assertSame(array_map(fn ($part) => $part.'/20', range(1, 20)), array_values($parts));
    }

    public function test_bytes_are_formatted_for_humans(): void
    {
        $this->assertSame('-', BackupListCommand::formatBytes(null));
        $this->assertSame('512 B', BackupListCommand::formatBytes(512));
        $this->assertSame('1.5 MB', BackupListCommand::formatBytes(1572864));
    }

    public function test_summary_of_a_backup_is_read_from_the_json_output(): void
    {
        $output = '{"message_type":"status","percent_done":0.5}'."\n".
            '{"message_type":"summary","total_bytes_processed":1288490189,"data_added":10485760,"data_added_packed":3565158,"total_duration":5.23}'."\n";

        $summary = Restic::backupSummary($output);

        $this->assertSame(1288490189, $summary['total_bytes_processed']);
        $this->assertSame('<comment>1.2 GB</comment>, added 3.4 MB in 5.2s', Backup::describeSummary($summary));
        $this->assertNull(Restic::backupSummary('not json'));
    }

    public function test_missing_directories_are_not_offered(): void
    {
        $existing = ['/etc/php', '/etc/mysql', '/etc/ssh/sshd_config', '/var/spool/cron/crontabs'];

        [$kept, $missing] = Restic::filterExistingDirectories(implode(';', Backup::DEFAULT_DIRECTORIES), fn ($path) => in_array($path, $existing));

        $this->assertSame('/etc/php;/etc/mysql --exclude="*/debian.cnf";/etc/ssh/sshd_config;/var/spool/cron/crontabs', $kept);
        $this->assertSame(['/etc/nginx'], $missing);
    }
}
