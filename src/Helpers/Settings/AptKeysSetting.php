<?php

namespace Gogol\VpsManagerCLI\Helpers\Settings;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;

/*
 * Signing keys of the APT repositories. A key is copied to the server once, at the installation; its vendor later
 * extends the expiry of the same key, but the copy on the server expires. The package which would bring the new key
 * (mysql-apt-config, debsuryorg-archive-keyring) lives in the very repository APT refuses then, so the key never
 * renews itself and unattended-upgrades silently stops updating MySQL or PHP. Known keys are downloaded again from
 * their vendors and replaced only when the fingerprint is the same.
 */
class AptKeysSetting extends Setting
{
    /**
     * Known keys by their fingerprints: name and url of the renewed key.
     */
    const KNOWN = [
        'BCA43417C3B485DD128EC6D4B7B3B788A8D3785C' => ['MySQL', 'https://repo.mysql.com/RPM-GPG-KEY-mysql-2025'],
        '15058500A0235D97F5D10063B188E2B695BD4743' => ['Sury PHP', 'https://packages.sury.org/php/apt.gpg'],
    ];

    /**
     * End of the support of the releases (the standard support of Ubuntu, the LTS of Debian).
     */
    const END_OF_LIFE = [
        'ubuntu' => ['16.04' => '2021-04-30', '18.04' => '2023-05-31', '20.04' => '2025-05-31', '22.04' => '2027-06-01', '24.04' => '2029-05-31'],
        'debian' => ['9' => '2022-06-30', '10' => '2024-06-30', '11' => '2026-08-31', '12' => '2028-06-30', '13' => '2030-06-30'],
    ];

    /**
     * Key of the setting used by --only.
     *
     * @return string
     */
    public function key(): string
    {
        return 'apt-keys';
    }

    /**
     * Keys of a keyring from the output of gpg --show-keys --with-colons.
     *
     * @param  string  $colons
     * @param  int  $now
     * @return array list of ['fingerprint', 'uid', 'expires' => timestamp|null, 'expired' => bool]
     */
    public static function parseKeys(string $colons, int $now): array
    {
        $keys = [];
        $key = null;

        foreach (explode("\n", $colons) as $line) {
            $fields = explode(':', $line);

            if ($fields[0] === 'pub') {
                $keys[] = ['fingerprint' => null, 'uid' => null, 'expires' => $fields[6] !== '' ? (int) $fields[6] : null];
                $key = count($keys) - 1;
            } elseif ($fields[0] === 'sub') {
                // Fingerprints of the subkeys follow
                $key = null;
            } elseif ($key !== null && $fields[0] === 'fpr' && $keys[$key]['fingerprint'] === null) {
                $keys[$key]['fingerprint'] = $fields[9] ?? null;
            } elseif ($key !== null && $fields[0] === 'uid' && $keys[$key]['uid'] === null) {
                $keys[$key]['uid'] = $fields[9] ?? null;
            }
        }

        return array_map(fn ($key) => $key + ['expired' => $key['expires'] !== null && $key['expires'] <= $now], $keys);
    }

    /**
     * Keyrings of the repositories: signed-by of the sources (also Signed-By of the deb822 .sources), the files of
     * trusted.gpg.d and the old trusted.gpg.
     *
     * @param  array  $sources  contents of the files of the sources
     * @return array paths
     */
    public static function keyringsOf(array $sources): array
    {
        $paths = [];

        foreach ($sources as $content) {
            preg_match_all('/signed-by\s*[=:]\s*([^\]\s,]+)/i', $content, $matches);
            $paths = array_merge($paths, $matches[1]);
        }

        return array_values(array_unique($paths));
    }

    /**
     * Date of the end of the support of the release, null when it is unknown.
     *
     * @param  string  $id  e.g. ubuntu, debian
     * @param  string  $version  e.g. 18.04, 12
     * @return string|null
     */
    public static function endOfLife(string $id, string $version): ?string
    {
        return self::END_OF_LIFE[strtolower($id)][$version] ?? null;
    }

    /**
     * Read the keyrings and the release of the system.
     *
     * @return array
     */
    protected function inspect(): array
    {
        if (! is_dir('/etc/apt') || ! trim((string) shell_exec('command -v gpg 2> /dev/null'))) {
            return ['installed' => false];
        }

        $sources = [];

        foreach (array_merge(['/etc/apt/sources.list'], glob('/etc/apt/sources.list.d/*.{list,sources}', GLOB_BRACE) ?: []) as $path) {
            $sources[] = (string) @file_get_contents($path);
        }

        $keyrings = array_merge(
            static::keyringsOf($sources),
            glob('/etc/apt/trusted.gpg.d/*.{gpg,asc}', GLOB_BRACE) ?: [],
            ['/etc/apt/trusted.gpg'],
        );

        $keys = 0;
        $renew = [];
        $warnings = [];

        foreach (array_unique($keyrings) as $path) {
            if (! is_file($path)) {
                continue;
            }

            exec('gpg --show-keys --with-colons '.escapeshellarg($path).' 2> /dev/null', $lines);
            $parsed = static::parseKeys(implode("\n", $lines), time());
            $lines = [];
            $keys += count($parsed);

            foreach ($parsed as $key) {
                if (! $key['expired']) {
                    continue;
                }

                if (isset(self::KNOWN[$key['fingerprint']])) {
                    $renew[] = ['path' => $path, 'fingerprint' => $key['fingerprint'], 'single' => count($parsed) === 1];
                } else {
                    $warnings[] = 'Key '.$key['fingerprint'].' ('.$key['uid'].') in '.$path.' expired on '.date('Y-m-d', $key['expires']).', renew it by hand or remove it when no repository uses it.';
                }
            }
        }

        $release = parse_ini_string((string) @file_get_contents('/etc/os-release')) ?: [];
        $end = static::endOfLife($release['ID'] ?? '', $release['VERSION_ID'] ?? '');

        if ($end && $end < date('Y-m-d')) {
            $warnings[] = ($release['PRETTY_NAME'] ?? 'The system').' has no support since '.$end.', upgrade the system.';
        }

        return [
            'installed' => true,
            'keys' => $keys,
            'renew' => $renew,
            'warnings' => $warnings,
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
            return 'no apt';
        }

        $expired = count($state['renew']) + count(preg_grep('/^Key /', $state['warnings']));

        return $state['keys'].' keys'.($expired ? ', '.$expired.' expired' : '');
    }

    /**
     * Recommended value of the setting for the table.
     *
     * @return string
     */
    public function recommended(): string
    {
        return $this->state()['installed'] ? 'none expired' : '-';
    }

    /**
     * Changes which would be made.
     *
     * @return array
     */
    public function plan(): array
    {
        $state = $this->state();

        if (! $state['installed'] || ! $state['renew']) {
            return [];
        }

        return array_merge(
            array_map(fn ($renew) => 'renew the key of '.self::KNOWN[$renew['fingerprint']][0].' in '.$renew['path'], $state['renew']),
            ['apt-get update'],
        );
    }

    /**
     * Download the renewed keys, check their fingerprints, replace them and check apt-get update.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Closure  $confirm
     * @return bool
     */
    public function apply(OutputInterface $output, Closure $confirm): bool
    {
        $ok = true;

        foreach ($this->state()['renew'] as $renew) {
            [$name, $url] = self::KNOWN[$renew['fingerprint']];
            $temporary = tempnam(sys_get_temp_dir(), 'vpsmanager-key-');

            if (! $this->run('curl -fsSL '.escapeshellarg($url).' -o '.escapeshellarg($temporary), $lines)) {
                $output->writeln('<error>The key of '.$name.' could not be downloaded from '.$url.'.</error>');
                $ok = false;

                continue;
            }

            exec('gpg --show-keys --with-colons '.escapeshellarg($temporary).' 2> /dev/null', $lines);
            $downloaded = static::parseKeys(implode("\n", $lines), time())[0] ?? null;
            $lines = [];

            // Only the same key with a later expiry replaces the old one
            if (! $downloaded || $downloaded['fingerprint'] !== $renew['fingerprint'] || $downloaded['expired']) {
                $output->writeln('<error>'.$url.' has no renewed key '.$renew['fingerprint'].'.</error>');
                @unlink($temporary);
                $ok = false;

                continue;
            }

            if (! $this->replace($renew, $temporary, $output)) {
                $ok = false;
            }

            @unlink($temporary);

            $output->writeln('Renewed the key of <info>'.$name.'</info> in '.$renew['path'].', it expires on '.date('Y-m-d', $downloaded['expires'] ?? 0).'.');
        }

        $this->run('apt-get update', $lines);
        $errors = preg_grep('/EXPKEYSIG|NO_PUBKEY|^E:/', $lines);

        if ($errors) {
            $output->writeln('<error>apt-get update: '.implode(' ', array_slice($errors, 0, 3)).'</error>');

            return false;
        }

        $output->writeln('apt-get update has no errors of the keys.');

        return $ok;
    }

    /**
     * Replace the key in its keyring: a file of one key is written again (armored for .asc), a key of a keyring with
     * more keys is imported into it, which updates its expiry.
     *
     * @param  array  $renew
     * @param  string  $temporary
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @return bool
     */
    protected function replace(array $renew, string $temporary, OutputInterface $output): bool
    {
        $path = $renew['path'];
        $armored = str_starts_with((string) file_get_contents($temporary, false, null, 0, 5), '-----');

        if (! $renew['single']) {
            $this->backup($path);

            if (! $this->run('gpg --no-default-keyring --keyring '.escapeshellarg($path).' --import '.escapeshellarg($temporary), $lines)) {
                $output->writeln('<error>'.$path.': '.implode(' ', $lines).'</error>');

                return false;
            }

            return true;
        }

        if (str_ends_with($path, '.asc')) {
            $content = $armored ? file_get_contents($temporary) : shell_exec('gpg --enarmor < '.escapeshellarg($temporary).' 2> /dev/null');
        } else {
            $content = $armored ? shell_exec('gpg --dearmor < '.escapeshellarg($temporary).' 2> /dev/null') : file_get_contents($temporary);
        }

        if (! $content) {
            $output->writeln('<error>The key for '.$path.' could not be converted.</error>');

            return false;
        }

        return $this->write($path, $content, $output);
    }
}
