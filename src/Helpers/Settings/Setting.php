<?php

namespace Gogol\VpsManagerCLI\Helpers\Settings;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;

/*
 * One recommended setting of the server managed by setup:run. A setting reads the system once (inspect()),
 * shows its current and recommended value, plans the changes and applies them. A new setting is a new subclass
 * registered in SetupRunCommand::settings().
 */
abstract class Setting
{
    /**
     * Header line of the files written by setup:run.
     */
    const MANAGED = 'Managed by VPS Manager (setup:run), changes are overwritten.';

    /**
     * State read from the system by inspect().
     *
     * @var array|null
     */
    private $state = null;

    /**
     * Time of the backups of this run, one suffix for all files changed together.
     *
     * @var int|null
     */
    protected static $backupTime = null;

    /**
     * Key of the setting used by --only.
     *
     * @return string
     */
    abstract public function key(): string;

    /**
     * Read the current state of the setting from the system.
     *
     * @return array
     */
    abstract protected function inspect(): array;

    /**
     * Current value of the setting for the table.
     *
     * @return string
     */
    abstract public function current(): string;

    /**
     * Recommended value of the setting for the table, computed from the server.
     *
     * @return string
     */
    abstract public function recommended(): string;

    /**
     * Changes which would be made, empty when the setting is up to date.
     *
     * @return array
     */
    abstract public function plan(): array;

    /**
     * Apply the planned changes and verify the result.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Closure  $confirm  fn (string $question, bool $default): bool, asks before a risky step
     * @return bool
     */
    abstract public function apply(OutputInterface $output, Closure $confirm): bool;

    /**
     * Notes about the setting printed under the table.
     *
     * @return array
     */
    public function warnings(): array
    {
        return $this->state()['warnings'] ?? [];
    }

    /**
     * Determine if the whole setting must be confirmed even when the other changes are applied.
     *
     * @return bool
     */
    public function isRisky(): bool
    {
        return false;
    }

    /**
     * Get the state read from the system, inspected once.
     *
     * @return array
     */
    protected function state(): array
    {
        return $this->state ??= $this->inspect();
    }

    /**
     * Forget the inspected state, the next call reads the system again.
     *
     * @return void
     */
    public function refresh(): void
    {
        $this->state = null;
    }

    /**
     * Path of the backup of a file before its change.
     *
     * @param  string  $path
     * @param  int|null  $time
     * @return string
     */
    public static function backupPath(string $path, ?int $time = null): string
    {
        return $path.'.vpsmanager-'.date('Ymd-His', $time ?? time()).'.bak';
    }

    /**
     * Copy the file next to itself before its change.
     *
     * @param  string  $path
     * @return string|null path of the backup, null when the file does not exist
     */
    protected function backup(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $backup = static::backupPath($path, static::$backupTime ??= time());

        if (! file_exists($backup)) {
            copy($path, $backup);
            chmod($backup, fileperms($path) & 0777);
        }

        return $backup;
    }

    /**
     * Write the file when its content differs, the previous content is backed up.
     *
     * @param  string  $path
     * @param  string  $content
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  int  $mode
     * @return bool
     */
    protected function write(string $path, string $content, OutputInterface $output, int $mode = 0644): bool
    {
        if (is_file($path) && file_get_contents($path) === $content) {
            return true;
        }

        $backup = $this->backup($path);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        if (file_put_contents($path, $content) === false) {
            $output->writeln('<error>'.$path.' could not be written.</error>');

            return false;
        }

        chmod($path, $mode);

        $output->writeln('Written <info>'.$path.'</info>'.($backup ? ' (backup '.$backup.')' : '').'.');

        return true;
    }

    /**
     * Put the previous content of the files back after a failed change.
     *
     * @param  array  $previous  path => content, null for a new file
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  string  $message
     * @return bool always false
     */
    protected function restore(array $previous, OutputInterface $output, string $message): bool
    {
        foreach ($previous as $path => $content) {
            if ($content === null) {
                @unlink($path);
            } else {
                file_put_contents($path, $content);
            }
        }

        $output->writeln('<comment>'.$message.'</comment>');

        return false;
    }

    /**
     * Header of a file written by setup:run.
     *
     * @param  string  $description
     * @return string
     */
    protected function header(string $description): string
    {
        return '# '.str_replace("\n", "\n# ", $description)."\n#\n# ".self::MANAGED."\n\n";
    }

    /**
     * Run a shell command.
     *
     * @param  string  $command
     * @param  array|null  $lines  output of the command
     * @return bool true when the command succeeded
     */
    protected function run(string $command, &$lines = null): bool
    {
        $lines = [];

        exec($command.' 2>&1', $lines, $return_var);

        return $return_var === 0;
    }

    /**
     * Human readable size.
     *
     * @param  int|float  $bytes
     * @return string
     */
    public static function size(int|float $bytes): string
    {
        if ($bytes >= SystemInfo::GB) {
            return number_format($bytes / SystemInfo::GB, 1, '.', '').' GB';
        }

        if ($bytes >= SystemInfo::MB) {
            return round($bytes / SystemInfo::MB).' MB';
        }

        return round($bytes / 1024).' kB';
    }
}
