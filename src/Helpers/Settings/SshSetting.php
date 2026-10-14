<?php

namespace Gogol\VpsManagerCLI\Helpers\Settings;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;

/*
 * Logins to SSH only by keys. sshd takes the first value of an option, so the managed file 00-vpsmanager.conf wins
 * over 50-cloud-init.conf and the main sshd_config after its Include. The main sshd_config is not changed, except
 * of the Include when it is missing.
 */
class SshSetting extends Setting
{
    const CONFIG = '/etc/ssh/sshd_config';

    const DIRECTORY = '/etc/ssh/sshd_config.d';

    const FILE = self::DIRECTORY.'/00-vpsmanager.conf';

    const INCLUDE = 'Include /etc/ssh/sshd_config.d/*.conf';

    /**
     * Options of sshd by their lowercase names.
     */
    const OPTIONS = [
        'passwordauthentication' => 'PasswordAuthentication',
        'kbdinteractiveauthentication' => 'KbdInteractiveAuthentication',
        'permitrootlogin' => 'PermitRootLogin',
    ];

    /**
     * Key of the setting used by --only.
     *
     * @return string
     */
    public function key(): string
    {
        return 'ssh';
    }

    /**
     * Changing sshd may lock the server.
     *
     * @return bool
     */
    public function isRisky(): bool
    {
        return true;
    }

    /**
     * Target values of the options, a stricter PermitRootLogin is kept.
     *
     * @param  string|null  $permitRootLogin  current value
     * @return array lowercase option => value
     */
    public static function targets(?string $permitRootLogin): array
    {
        return [
            'passwordauthentication' => 'no',
            'kbdinteractiveauthentication' => 'no',
            'permitrootlogin' => in_array($permitRootLogin, ['no', 'forced-commands-only'], true) ? $permitRootLogin : 'prohibit-password',
        ];
    }

    /**
     * Effective values of the options: the first value wins, Include is expanded in place, a Match block ends the
     * global options of its file.
     *
     * @param  string  $main  content of sshd_config
     * @param  array  $files  path => content of the included files
     * @return array lowercase option => value
     */
    public static function effective(string $main, array $files): array
    {
        $values = [];

        static::readOptions($main, $files, $values);

        return static::normalize($values);
    }

    /**
     * Keep the managed options with their current names: ChallengeResponseAuthentication is the old name of
     * KbdInteractiveAuthentication, without-password of prohibit-password.
     *
     * @param  array  $values
     * @return array
     */
    public static function normalize(array $values): array
    {
        if (isset($values['challengeresponseauthentication']) && ! isset($values['kbdinteractiveauthentication'])) {
            $values['kbdinteractiveauthentication'] = $values['challengeresponseauthentication'];
        }

        if (($values['permitrootlogin'] ?? null) === 'without-password') {
            $values['permitrootlogin'] = 'prohibit-password';
        }

        return array_intersect_key($values, self::OPTIONS);
    }

    /**
     * Read the options of a config file into the values, the first value wins.
     *
     * @param  string  $content
     * @param  array  $files
     * @param  array  $values
     * @return void
     */
    protected static function readOptions(string $content, array $files, array &$values): void
    {
        foreach (explode("\n", $content) as $line) {
            $parts = preg_split('/[\s=]+/', trim($line), 2);
            $option = strtolower($parts[0] ?? '');

            if ($option === '' || str_starts_with($option, '#')) {
                continue;
            }

            if ($option === 'match') {
                return;
            }

            if ($option === 'include') {
                foreach (preg_split('/\s+/', trim($parts[1] ?? '')) as $pattern) {
                    $pattern = str_starts_with($pattern, '/') ? $pattern : '/etc/ssh/'.$pattern;
                    $included = array_filter(array_keys($files), fn ($path) => fnmatch($pattern, $path, FNM_PATHNAME));
                    sort($included);

                    foreach ($included as $path) {
                        static::readOptions($files[$path], $files, $values);
                    }
                }

                continue;
            }

            $values[$option] ??= strtolower(trim($parts[1] ?? ''));
        }
    }

    /**
     * Content of the managed file.
     *
     * @param  array  $targets
     * @return string
     */
    public function content(array $targets): string
    {
        $content = $this->header("Logins to SSH only by keys. sshd takes the first value of an option, this file is included\n"
            .'before the other files of sshd_config.d (e.g. 50-cloud-init.conf).');

        foreach ($targets as $option => $value) {
            $content .= self::OPTIONS[$option].' '.$value."\n";
        }

        return $content;
    }

    /**
     * Content of sshd_config with the Include of sshd_config.d at its start when it is missing.
     *
     * @param  string  $main
     * @return string
     */
    public static function withInclude(string $main): string
    {
        if (preg_match('#^\s*Include\s+(/etc/ssh/)?sshd_config\.d/\*\.conf\s*$#mi', $main)) {
            return $main;
        }

        return self::INCLUDE."\n\n".$main;
    }

    /**
     * Included files of sshd_config.d.
     *
     * @param  array  $replace  path => content replacing the files on the disk
     * @return array path => content
     */
    protected function files(array $replace = []): array
    {
        $files = [];

        foreach (glob(self::DIRECTORY.'/*.conf') ?: [] as $path) {
            $files[$path] = (string) file_get_contents($path);
        }

        return array_merge($files, $replace);
    }

    /**
     * Effective values of the running configuration by sshd -T.
     *
     * @return array|null lowercase option => value, null when sshd fails
     */
    protected function sshdValues(): ?array
    {
        // sshd -T needs the directory of the privilege separation, a socket activated sshd may not have it yet
        if (! is_dir('/run/sshd')) {
            @mkdir('/run/sshd', 0755);
        }

        if (! $this->run('sshd -T', $lines)) {
            return null;
        }

        $values = [];

        foreach ($lines as $line) {
            [$option, $value] = array_pad(explode(' ', $line, 2), 2, '');

            $values[$option] = $value;
        }

        return static::normalize($values);
    }

    /**
     * Read the effective options and the files of sshd.
     *
     * @return array
     */
    protected function inspect(): array
    {
        if (! is_file(self::CONFIG) || ! trim((string) shell_exec('command -v sshd 2> /dev/null'))) {
            return ['installed' => false];
        }

        $values = $this->sshdValues() ?? static::effective((string) file_get_contents(self::CONFIG), $this->files());
        $targets = static::targets($values['permitrootlogin'] ?? null);
        $content = $this->content($targets);
        $main = (string) file_get_contents(self::CONFIG);

        return [
            'installed' => true,
            'values' => $values,
            'targets' => $targets,
            'content' => $content,
            'include' => static::withInclude($main) !== $main,
            // The values after the change by the files, before any restart
            'planned' => static::effective(static::withInclude($main), $this->files([self::FILE => $content])),
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

        if (! $state['installed']) {
            return 'not installed';
        }

        return 'password '.($state['values']['passwordauthentication'] ?? '?').', root '.($state['values']['permitrootlogin'] ?? '?');
    }

    /**
     * Recommended value of the setting for the table.
     *
     * @return string
     */
    public function recommended(): string
    {
        $state = $this->state();

        if (! $state['installed']) {
            return '-';
        }

        return 'password no, root '.$state['targets']['permitrootlogin'];
    }

    /**
     * Changes which would be made: none when the running sshd already has the values.
     *
     * @return array
     */
    public function plan(): array
    {
        $state = $this->state();

        if (! $state['installed'] || array_intersect_key($state['values'], $state['targets']) == $state['targets']) {
            return [];
        }

        return array_merge(
            ['write '.basename(self::FILE)],
            $state['include'] ? ['add Include to sshd_config'] : [],
            ['restart ssh'],
        );
    }

    /**
     * Problems of the configuration which the managed file cannot fix.
     *
     * @return array
     */
    public function warnings(): array
    {
        $state = $this->state();

        if (! $state['installed'] || ! $this->plan()) {
            return [];
        }

        $warnings = [];

        if ($state['planned'] != $state['targets']) {
            $warnings[] = 'Another option of sshd_config wins over '.self::FILE.' (before its Include or in a Match block), it must be fixed by hand.';
        }

        if ($reason = $this->lockoutReason()) {
            $warnings[] = 'Passwords cannot be turned off: '.$reason;
        }

        return $warnings;
    }

    /**
     * Determine if the line of authorized_keys holds a key, options may precede it.
     *
     * @param  string  $line
     * @return bool
     */
    public static function isKey(string $line): bool
    {
        return ! str_starts_with(trim($line), '#') && preg_match('/(^|\s)(ssh-|ecdsa-|sk-)[\w@.-]+\s+AAAA/', trim($line)) === 1;
    }

    /**
     * Check that root can log in by a key and that this session came by a key.
     *
     * @return string|null reason why the passwords cannot be turned off
     */
    public function lockoutReason(): ?string
    {
        $keys = array_filter(@file('/root/.ssh/authorized_keys', FILE_IGNORE_NEW_LINES) ?: [], [static::class, 'isKey']);

        if (! $keys) {
            return '/root/.ssh/authorized_keys has no key, root could not log in without a password.';
        }

        $method = $this->sessionMethod();

        if ($method === null) {
            return 'This session did not come over SSH or its login was not found in the log of sshd, the login by a key is not proven.';
        }

        if ($method !== 'publickey') {
            return 'This session logged in by '.$method.', log in by a key first.';
        }

        return null;
    }

    /**
     * Method of the login of this session from the log of sshd, e.g. publickey or password.
     *
     * @return string|null
     */
    protected function sessionMethod(): ?string
    {
        [$ip, $port] = $this->sessionAddress();

        if (! $ip) {
            return null;
        }

        $pattern = '/Accepted (\S+) for \S+ from '.preg_quote($ip, '/').' port '.($port ? preg_quote($port, '/').'\b' : '\d+').'/';

        exec('journalctl -u ssh -u sshd --no-pager -o cat --since "-7 days" 2> /dev/null | grep -a "Accepted "', $journal);

        // The log of rsyslog when the journal does not have the login, auth.log of a scanned server is big
        exec('grep -a "Accepted " /var/log/auth.log 2> /dev/null', $authLog);

        foreach ([$journal, $authLog] as $lines) {
            $method = null;

            // The last login of the address
            foreach ($lines as $line) {
                if (preg_match($pattern, $line, $match)) {
                    $method = $match[1];
                }
            }

            if ($method) {
                return $method;
            }
        }

        return null;
    }

    /**
     * Address and port of the client of this session: SSH_CONNECTION, which sudo usually drops, or the host of the
     * terminal by who -m.
     *
     * @return array [ip, port]
     */
    protected function sessionAddress(): array
    {
        if ($connection = getenv('SSH_CONNECTION')) {
            $parts = explode(' ', $connection);

            return [$parts[0] ?? null, $parts[1] ?? null];
        }

        if (preg_match('/\(([0-9a-f.:]+)\)\s*$/i', trim((string) shell_exec('who -m 2> /dev/null')), $match)) {
            return [$match[1], null];
        }

        return [null, null];
    }

    /**
     * Write the managed file, check the configuration and restart sshd.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Closure  $confirm
     * @return bool
     */
    public function apply(OutputInterface $output, Closure $confirm): bool
    {
        $state = $this->state();

        if ($reason = $this->lockoutReason()) {
            $output->writeln('<error>SSH is not changed: '.$reason.'</error>');

            return false;
        }

        $previous = [
            self::FILE => is_file(self::FILE) ? file_get_contents(self::FILE) : null,
            self::CONFIG => file_get_contents(self::CONFIG),
        ];

        if (! $this->write(self::FILE, $state['content'], $output, 0600)) {
            return false;
        }

        if ($state['include'] && ! $this->write(self::CONFIG, static::withInclude($previous[self::CONFIG]), $output)) {
            return $this->restore($previous, $output, 'The files of sshd have been restored, SSH has not been restarted.');
        }

        $values = $this->sshdValues();

        if ($values === null) {
            $output->writeln('<error>sshd -t found an error in the configuration.</error>');

            return $this->restore($previous, $output, 'The files of sshd have been restored, SSH has not been restarted.');
        }

        if (array_intersect_key($values, $state['targets']) != $state['targets']) {
            $output->writeln('<error>sshd -T has '.http_build_query($values, '', ', ').', another option wins over '.self::FILE.'.</error>');

            return $this->restore($previous, $output, 'The files of sshd have been restored, SSH has not been restarted.');
        }

        if (! vpsManager()->ssh()->restart()) {
            $output->writeln('<error>SSH could not be restarted.</error>');

            return $this->restore($previous, $output, 'The files of sshd have been restored, SSH has not been restarted.');
        }

        $output->writeln('SSH has been restarted: <info>'.http_build_query($values, '', ', ').'</info>.');
        $output->writeln('<comment>Open a new SSH login in another window before you log out, this session stays open.</comment>');

        return true;
    }
}
