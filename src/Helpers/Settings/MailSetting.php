<?php

namespace Gogol\VpsManagerCLI\Helpers\Settings;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;

/*
 * Size of the mailboxes in /var/mail. Cron mails the output of the jobs of a hosting to its user, nobody reads
 * it (2 GB on cech1990.sk from ecosun:orders every 10 minutes). Only a note, the jobs belong to the hostings:
 * their output goes to a log, e.g. >> storage/logs/cron.log 2>&1, which the rotation of Laravel logs keeps.
 */
class MailSetting extends Setting
{
    const DIRECTORY = '/var/mail';

    /**
     * A bigger mailbox is noted.
     */
    const MAX_SIZE = 100 * SystemInfo::MB;

    /**
     * Key of the setting used by --only.
     *
     * @return string
     */
    public function key(): string
    {
        return 'mail';
    }

    /**
     * Notes about the big mailboxes.
     *
     * @param  array  $sizes  path => bytes
     * @return array
     */
    public static function notes(array $sizes): array
    {
        $notes = [];

        foreach ($sizes as $path => $size) {
            if ($size > self::MAX_SIZE) {
                $notes[] = $path.' has '.static::size($size).', mostly the output of cron jobs: send it to a log (>> storage/logs/cron.log 2>&1) and archive the mailbox.';
            }
        }

        return $notes;
    }

    /**
     * Read the sizes of the mailboxes.
     *
     * @return array
     */
    protected function inspect(): array
    {
        $sizes = [];

        foreach (glob(self::DIRECTORY.'/*') ?: [] as $path) {
            if (is_file($path) && ! is_link($path)) {
                $sizes[$path] = (int) filesize($path);
            }
        }

        arsort($sizes);

        return ['sizes' => $sizes, 'warnings' => static::notes($sizes)];
    }

    /**
     * Current value of the setting for the table.
     *
     * @return string
     */
    public function current(): string
    {
        $sizes = $this->state()['sizes'];

        return $sizes ? 'biggest '.static::size(reset($sizes)).' ('.basename(array_key_first($sizes)).')' : 'no mailboxes';
    }

    /**
     * Recommended value of the setting for the table.
     *
     * @return string
     */
    public function recommended(): string
    {
        return 'under '.static::size(self::MAX_SIZE);
    }

    /**
     * Nothing is changed, the big mailboxes are only noted.
     *
     * @return array
     */
    public function plan(): array
    {
        return [];
    }

    /**
     * Nothing is changed.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Closure  $confirm
     * @return bool
     */
    public function apply(OutputInterface $output, Closure $confirm): bool
    {
        return true;
    }
}
