<?php

namespace Tests\Unit;

use Gogol\VpsManagerCLI\Helpers\Cron;
use PHPUnit\Framework\TestCase;

class CronTest extends TestCase
{
    public function test_backup_jobs_with_databases_every_six_hours(): void
    {
        $this->assertSame([
            '0 4 * * * root vm backup:run > /dev/null 2>&1',
            '0 0,6,12,18 * * * root vm backup:run --databases > /dev/null 2>&1',
            '30 5 * * 0 root vm backup:maintenance > /dev/null 2>&1',
        ], Cron::backupJobs('vm', 6));
    }

    public function test_databases_skip_the_hour_of_the_daily_backup(): void
    {
        $jobs = Cron::backupJobs('vm', 2);

        $this->assertSame('0 0,2,6,8,10,12,14,16,18,20,22 * * * root vm backup:run --databases > /dev/null 2>&1', $jobs[1]);
    }

    public function test_databases_only_daily(): void
    {
        $this->assertSame([
            '0 4 * * * root vm backup:run > /dev/null 2>&1',
            '30 5 * * 0 root vm backup:maintenance > /dev/null 2>&1',
        ], Cron::backupJobs('vm', 0));

        // An interval which does not divide the day is ignored
        $this->assertCount(2, Cron::backupJobs('vm', 5));
    }

    public function test_old_backup_jobs_are_removed_from_the_crontab_of_root(): void
    {
        [$kept, $removed] = Cron::filterRootCrontab([
            '# m h dom mon dow command',
            '0 4 * * * php /root/vpsmanager/vpsmanager backup:run',
            '0 */6 * * * php /root/vpsmanager/vpsmanager backup:run --databases',
            '30 5 * * 0 php /root/vpsmanager/vpsmanager backup:maintenance',
            '# 0 1 * * * php /root/vpsmanager/vpsmanager backup:run',
            '15 2 * * * /usr/local/bin/other-job',
        ]);

        $this->assertSame(['# m h dom mon dow command', '# 0 1 * * * php /root/vpsmanager/vpsmanager backup:run', '15 2 * * * /usr/local/bin/other-job'], $kept);
        $this->assertCount(3, $removed);
    }
}
