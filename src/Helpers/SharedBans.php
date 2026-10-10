<?php

namespace Gogol\VpsManagerCLI\Helpers;

/**
 * Levels of the bans shared through the monitor and the plan of monitor:sync. The first ban of a repeated scanner
 * lasts a day, the next one 7 days, then 30 days (bantime.multipliers of the recidive jail). Every level is shared
 * and banned by the other servers in the jail of its length, so a ban expires by itself also without the monitor.
 */
class SharedBans
{
    /**
     * Levels of the bans in days, the same as in the monitor.
     */
    const LEVELS = [1, 7, 30];

    /**
     * Jails of the shared bans by their level.
     */
    const JAILS = [
        1 => 'vpsmanager-shared-1d',
        7 => 'vpsmanager-shared-7d',
        30 => 'vpsmanager-shared-30d',
    ];

    /**
     * Level of a ban which lasts the given number of seconds.
     *
     * @param  int  $seconds
     * @return int days
     */
    public static function levelOf(int $seconds): int
    {
        foreach (self::LEVELS as $days) {
            if ($seconds <= $days * 86400) {
                return $days;
            }
        }

        return max(self::LEVELS);
    }

    /**
     * Jail of the shared bans of the level, an unknown level is the longest one.
     *
     * @param  int  $days
     * @return string
     */
    public static function jailOf(int $days): string
    {
        foreach (self::LEVELS as $level) {
            if ($days <= $level) {
                return self::JAILS[$level];
            }
        }

        return self::JAILS[max(self::LEVELS)];
    }

    /**
     * Addresses for the monitor from the bans of the recidive jail: their level and the end of the ban.
     *
     * @param  array  $bans  list of ['ip', 'banned_at', 'expires_at'] of Fail2ban::getBans(), in the local time of the server
     * @param  string  $offset  timezone offset of the server, e.g. +0200
     * @param  string  $reason
     * @return array list of ['ip', 'reason', 'days', 'expires_at']
     */
    public static function report(array $bans, string $offset, string $reason): array
    {
        $rows = [];

        foreach ($bans as $ban) {
            $bannedAt = strtotime($ban['banned_at'].' UTC');
            $expiresAt = strtotime($ban['expires_at'].' UTC');

            if ($bannedAt === false || $expiresAt === false) {
                continue;
            }

            $rows[$ban['ip']] = [
                'ip' => $ban['ip'],
                'reason' => $reason,
                'days' => self::levelOf($expiresAt - $bannedAt),
                'expires_at' => str_replace(' ', 'T', $ban['expires_at']).$offset,
            ];
        }

        return array_values($rows);
    }

    /**
     * Changes of the shared jails: the wanted addresses in the jail of their level, an address in another jail moves.
     *
     * @param  array  $shared  ip => days, shared by the monitor
     * @param  array  $current  jail => list of banned addresses
     * @param  array  $skip  addresses never banned by the shared jails (own, private, already banned by the recidive jail)
     * @return array jail => ['ban' => [...], 'unban' => [...]], only jails with changes
     */
    public static function plan(array $shared, array $current, array $skip = []): array
    {
        $wanted = array_fill_keys(self::JAILS, []);

        foreach ($shared as $ip => $days) {
            if (! in_array($ip, $skip, true)) {
                $wanted[self::jailOf((int) $days)][] = (string) $ip;
            }
        }

        $plan = [];

        foreach (self::JAILS as $jail) {
            $banned = $current[$jail] ?? [];

            $changes = [
                'ban' => array_values(array_diff($wanted[$jail], $banned)),
                'unban' => array_values(array_diff($banned, $wanted[$jail])),
            ];

            if (count($changes['ban']) || count($changes['unban'])) {
                $plan[$jail] = $changes;
            }
        }

        return $plan;
    }
}
