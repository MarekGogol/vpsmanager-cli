<?php

namespace Gogol\VpsManagerCLI\Helpers\Settings;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;

/*
 * Size of the journal of systemd. A drop-in wins over journald.conf. Small files let journald remove the old ones,
 * with files of 200 MB it cannot keep SystemMaxUse (qualit had 520 MB with SystemMaxUse=300M).
 */
class JournaldSetting extends Setting
{
    const FILE = '/etc/systemd/journald.conf.d/60-vpsmanager.conf';

    const MAX_USE = '200M';

    const MAX_FILE_SIZE = '32M';

    /**
     * Key of the setting used by --only.
     *
     * @return string
     */
    public function key(): string
    {
        return 'journald';
    }

    /**
     * Content of the drop-in.
     *
     * @return string
     */
    public function content(): string
    {
        return $this->header("Size of the journal of systemd, wins over /etc/systemd/journald.conf.\n"
            .'Small files let journald remove the old ones and keep SystemMaxUse.')
            ."[Journal]\n"
            .'SystemMaxUse='.self::MAX_USE."\n"
            .'SystemMaxFileSize='.self::MAX_FILE_SIZE."\n";
    }

    /**
     * Size of the journal in bytes from journalctl --disk-usage.
     *
     * @param  string  $output  e.g. "Archived and active journals take up 520.0M in the file system."
     * @return int
     */
    public static function parseUsage(string $output): int
    {
        if (! preg_match('/take up ([\d.]+)([BKMGT])/', $output, $match)) {
            return 0;
        }

        return (int) ($match[1] * 1024 ** strpos('BKMGT', $match[2]));
    }

    /**
     * Read the drop-in and the size of the journal.
     *
     * @return array
     */
    protected function inspect(): array
    {
        // A container without systemd has no journald
        if (! is_dir('/run/systemd/system') || ! trim((string) shell_exec('command -v journalctl 2> /dev/null'))) {
            return ['installed' => false];
        }

        return [
            'installed' => true,
            'usage' => static::parseUsage((string) shell_exec('journalctl --disk-usage 2> /dev/null')),
            'written' => is_file(self::FILE) && file_get_contents(self::FILE) === $this->content(),
        ];
    }

    /**
     * Current value of the setting for the table.
     *
     * @return string
     */
    public function current(): string
    {
        $state = $this->state();

        return $state['installed'] ? static::size($state['usage']) : 'not installed';
    }

    /**
     * Recommended value of the setting for the table.
     *
     * @return string
     */
    public function recommended(): string
    {
        return $this->state()['installed'] ? 'at most '.rtrim(self::MAX_USE, 'M').' MB' : '-';
    }

    /**
     * Changes which would be made.
     *
     * @return array
     */
    public function plan(): array
    {
        $state = $this->state();

        if (! $state['installed'] || $state['written'] && $state['usage'] <= 200 * SystemInfo::MB * 1.1) {
            return [];
        }

        return $state['written'] ? ['vacuum the journal'] : ['write '.self::FILE, 'restart journald, vacuum'];
    }

    /**
     * Write the drop-in, restart journald and remove the old files above the limit.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Closure  $confirm
     * @return bool
     */
    public function apply(OutputInterface $output, Closure $confirm): bool
    {
        if (! $this->write(self::FILE, $this->content(), $output)) {
            return false;
        }

        // Rotate the active file, so the vacuum may remove it too
        if (! $this->run('systemctl restart systemd-journald && journalctl --rotate && journalctl --vacuum-size='.self::MAX_USE, $lines)) {
            $output->writeln('<error>journald could not be restarted: '.implode(' ', $lines).'</error>');

            return false;
        }

        $output->writeln('The journal takes <info>'.static::size(static::parseUsage((string) shell_exec('journalctl --disk-usage 2> /dev/null'))).'</info>.');

        return true;
    }
}
