<?php

namespace Tests\Unit;

use Gogol\VpsManagerCLI\Helpers\SharedBans;
use PHPUnit\Framework\TestCase;

class SharedBansTest extends TestCase
{
    public function test_level_of_a_ban_follows_its_length(): void
    {
        $this->assertSame(1, SharedBans::levelOf(86400));
        $this->assertSame(7, SharedBans::levelOf(86400 * 7));
        $this->assertSame(30, SharedBans::levelOf(86400 * 30));

        // The second and the third ban of fail2ban (bantime.multipliers = 1 7 30), with a second of rounding
        $this->assertSame(7, SharedBans::levelOf(86400 + 1));
        $this->assertSame(30, SharedBans::levelOf(86400 * 7 + 1));
        $this->assertSame(30, SharedBans::levelOf(86400 * 90));
    }

    public function test_every_level_has_its_jail(): void
    {
        $this->assertSame('vpsmanager-shared-1d', SharedBans::jailOf(1));
        $this->assertSame('vpsmanager-shared-7d', SharedBans::jailOf(7));
        $this->assertSame('vpsmanager-shared-30d', SharedBans::jailOf(30));
    }

    public function test_unknown_level_is_the_jail_of_the_next_longer_level(): void
    {
        $this->assertSame('vpsmanager-shared-1d', SharedBans::jailOf(0));
        $this->assertSame('vpsmanager-shared-7d', SharedBans::jailOf(3));
        $this->assertSame('vpsmanager-shared-30d', SharedBans::jailOf(365));
    }

    public function test_jail_names_fit_into_iptables_chains(): void
    {
        // iptables chains are named f2b-<jail> and may have 28 characters at most
        foreach (SharedBans::JAILS as $jail) {
            $this->assertLessThanOrEqual(28, strlen('f2b-'.$jail), $jail);
        }
    }

    public function test_report_sends_the_level_and_the_end_of_every_ban(): void
    {
        $bans = [
            ['ip' => '20.92.77.159', 'banned_at' => '2026-10-10 20:00:00', 'expires_at' => '2026-10-11 20:00:00'],
            ['ip' => '45.138.12.11', 'banned_at' => '2026-10-10 20:00:00', 'expires_at' => '2026-10-17 20:00:00'],
            ['ip' => '2a02:2b88:1:2::5', 'banned_at' => '2026-10-10 20:00:00', 'expires_at' => '2026-11-09 20:00:00'],
        ];

        $this->assertSame([
            ['ip' => '20.92.77.159', 'reason' => 'vpsmanager-scanners-recidive', 'days' => 1, 'expires_at' => '2026-10-11T20:00:00+0200'],
            ['ip' => '45.138.12.11', 'reason' => 'vpsmanager-scanners-recidive', 'days' => 7, 'expires_at' => '2026-10-17T20:00:00+0200'],
            ['ip' => '2a02:2b88:1:2::5', 'reason' => 'vpsmanager-scanners-recidive', 'days' => 30, 'expires_at' => '2026-11-09T20:00:00+0200'],
        ], SharedBans::report($bans, '+0200', 'vpsmanager-scanners-recidive'));
    }

    public function test_report_is_not_shifted_by_a_change_of_summer_time(): void
    {
        // A week ban over the end of summer time, both times are local times of the server
        $bans = [['ip' => '20.92.77.159', 'banned_at' => '2026-10-22 12:00:00', 'expires_at' => '2026-10-29 12:00:00']];

        $this->assertSame(7, SharedBans::report($bans, '+0100', 'x')[0]['days']);
    }

    public function test_report_skips_unreadable_times(): void
    {
        $this->assertSame([], SharedBans::report([['ip' => '20.92.77.159', 'banned_at' => 'never', 'expires_at' => '']], '+0000', 'x'));
    }

    public function test_plan_bans_new_addresses_in_the_jail_of_their_level(): void
    {
        $plan = SharedBans::plan(['20.92.77.159' => 1, '45.138.12.11' => 7, '35.236.106.66' => 30], []);

        $this->assertSame([
            'vpsmanager-shared-1d' => ['ban' => ['20.92.77.159'], 'unban' => []],
            'vpsmanager-shared-7d' => ['ban' => ['45.138.12.11'], 'unban' => []],
            'vpsmanager-shared-30d' => ['ban' => ['35.236.106.66'], 'unban' => []],
        ], $plan);
    }

    public function test_plan_without_changes_is_empty(): void
    {
        $current = [
            'vpsmanager-shared-1d' => ['20.92.77.159'],
            'vpsmanager-shared-7d' => ['45.138.12.11'],
            'vpsmanager-shared-30d' => [],
        ];

        $this->assertSame([], SharedBans::plan(['20.92.77.159' => 1, '45.138.12.11' => 7], $current));
    }

    public function test_plan_unbans_addresses_the_monitor_stopped_sharing(): void
    {
        $plan = SharedBans::plan([], ['vpsmanager-shared-7d' => ['45.138.12.11']]);

        $this->assertSame(['vpsmanager-shared-7d' => ['ban' => [], 'unban' => ['45.138.12.11']]], $plan);
    }

    public function test_plan_moves_an_address_to_the_jail_of_its_new_level(): void
    {
        $plan = SharedBans::plan(['20.92.77.159' => 7], ['vpsmanager-shared-1d' => ['20.92.77.159']]);

        $this->assertSame([
            'vpsmanager-shared-1d' => ['ban' => [], 'unban' => ['20.92.77.159']],
            'vpsmanager-shared-7d' => ['ban' => ['20.92.77.159'], 'unban' => []],
        ], $plan);
    }

    public function test_plan_never_bans_skipped_addresses_and_unbans_them(): void
    {
        // Own address, private network or an address banned already by the recidive jail
        $plan = SharedBans::plan(['89.221.214.62' => 30, '20.92.77.159' => 1], ['vpsmanager-shared-30d' => ['89.221.214.62']], ['89.221.214.62']);

        $this->assertSame([
            'vpsmanager-shared-1d' => ['ban' => ['20.92.77.159'], 'unban' => []],
            'vpsmanager-shared-30d' => ['ban' => [], 'unban' => ['89.221.214.62']],
        ], $plan);
    }

    public function test_plan_of_a_monitor_without_levels_uses_the_longest_ban(): void
    {
        $this->assertSame(['vpsmanager-shared-30d' => ['ban' => ['20.92.77.159'], 'unban' => []]], SharedBans::plan(['20.92.77.159' => 30], []));
    }
}
