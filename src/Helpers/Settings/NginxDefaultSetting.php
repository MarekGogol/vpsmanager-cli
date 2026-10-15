<?php

namespace Gogol\VpsManagerCLI\Helpers\Settings;

use Closure;
use Symfony\Component\Console\Output\OutputInterface;

/*
 * HTTPS of the names no website of the server knows, e.g. jp.kampo.sk of a DNS wildcard or the address of the
 * server. The default server of NGINX refuses their TLS handshake (ssl_reject_handshake, NGINX 1.19.4+): a browser
 * shows a failed connection instead of a warning of a self-signed certificate, a scanner gets nothing and does not
 * learn the name of the server. Older NGINX needs a certificate in the default server, it keeps the snakeoil one.
 * Without a default server on 443 NGINX would answer an unknown name by the first website and its certificate.
 */
class NginxDefaultSetting extends Setting
{
    /**
     * First version of NGINX with ssl_reject_handshake.
     */
    const REJECT_VERSION = '1.19.4';

    const SNAKEOIL = 'include snippets/snakeoil.conf;';

    const REJECT = 'ssl_reject_handshake on;';

    /**
     * Key of the setting used by --only.
     *
     * @return string
     */
    public function key(): string
    {
        return 'nginx-default';
    }

    /**
     * Version of the installed NGINX, e.g. 1.22.1.
     *
     * @return string|null
     */
    public static function version(): ?string
    {
        return preg_match('/nginx\/([\d.]+)/', (string) shell_exec('nginx -v 2>&1'), $match) ? $match[1] : null;
    }

    /**
     * Determine if the version of NGINX refuses the TLS handshake without a certificate.
     *
     * @param  string|null  $version
     * @return bool
     */
    public static function rejects(?string $version): bool
    {
        return $version !== null && version_compare($version, self::REJECT_VERSION, '>=');
    }

    /**
     * Content of the default server listening on 443, refusing the handshake or with the snakeoil certificate.
     *
     * @param  string  $content  sites-available/default
     * @param  bool  $reject
     * @return string
     */
    public static function content(string $content, bool $reject): string
    {
        // Lines of 443 of the default file of Debian and Ubuntu are commented out
        $content = preg_replace('/^([ \t]*)#[ \t]*(listen (\[::\]:)?443 ssl default_server;)/m', '$1$2', $content);

        if (! preg_match('/^[ \t]*listen 443 ssl default_server;/m', $content)) {
            $content = preg_replace('/^([ \t]*)(listen (\[::\]:)?80 default_server;)(?![\s\S]*listen (\[::\]:)?80 default_server;)/m', "$1$2\n$1listen 443 ssl default_server;\n$1listen [::]:443 ssl default_server;", $content, 1);
        }

        $active = $reject ? self::REJECT : self::SNAKEOIL;
        $other = $reject ? self::SNAKEOIL : self::REJECT;

        // The other way is turned off
        $content = preg_replace('/^[ \t]*'.preg_quote($other, '/').'[ \t]*\n/m', '', $content);

        if (! preg_match('/^[ \t]*'.preg_quote($active, '/').'/m', $content)) {
            $commented = '/^([ \t]*)#[ \t]*'.preg_quote($active, '/').'/m';

            $content = preg_match($commented, $content)
                ? preg_replace($commented, '$1'.$active, $content, 1)
                : preg_replace('/^([ \t]*)(listen \[::\]:443 ssl default_server;)/m', "$1$2\n$1".$active, $content, 1);
        }

        return $content;
    }

    /**
     * Way of the default server for the table: reject, self-signed or none.
     *
     * @param  string  $content
     * @return string
     */
    public static function mode(string $content): string
    {
        if (! preg_match('/^[ \t]*listen 443 ssl default_server;/m', $content)) {
            return 'first website';
        }

        if (preg_match('/^[ \t]*'.preg_quote(self::REJECT, '/').'/m', $content)) {
            return 'reject';
        }

        return preg_match('/^[ \t]*'.preg_quote(self::SNAKEOIL, '/').'/m', $content) ? 'self-signed' : 'no certificate';
    }

    /**
     * Read the default server and the version of NGINX.
     *
     * @return array
     */
    protected function inspect(): array
    {
        if (! trim((string) shell_exec('command -v nginx 2> /dev/null'))) {
            return ['installed' => false];
        }

        $path = vpsManager()->nginx()->getAvailablePath('default');
        $enabled = file_exists(vpsManager()->nginx()->getEnabledPath('default'));
        $content = (string) @file_get_contents($path);
        $reject = static::rejects(static::version());

        $state = [
            'installed' => true,
            'path' => $path,
            'reject' => $reject,
            'mode' => $content !== '' && $enabled ? static::mode($content) : 'first website',
            'content' => null,
            'warnings' => [],
        ];

        if ($content === '' || ! $enabled) {
            $state['warnings'][] = 'No enabled default server ('.$path.'), NGINX answers an unknown name by the first website and its certificate.';

            return $state;
        }

        $state['content'] = static::content($content, $reject);
        $state['written'] = $state['content'] === $content;

        return $state;
    }

    /**
     * Current value of the setting for the table.
     *
     * @return string
     */
    public function current(): string
    {
        return $this->state()['installed'] ? $this->state()['mode'] : 'not installed';
    }

    /**
     * Recommended value of the setting for the table.
     *
     * @return string
     */
    public function recommended(): string
    {
        $state = $this->state();

        return $state['installed'] ? ($state['reject'] ? 'reject' : 'self-signed (NGINX < '.self::REJECT_VERSION.')') : '-';
    }

    /**
     * Changes which would be made.
     *
     * @return array
     */
    public function plan(): array
    {
        $state = $this->state();

        if (! $state['installed'] || $state['content'] === null || $state['written']) {
            return [];
        }

        return [$state['reject'] ? 'refuse TLS of unknown names' : 'snakeoil certificate of unknown names', 'reload NGINX'];
    }

    /**
     * Write the default server, test the configuration, reload NGINX and check an unknown name.
     *
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     * @param  \Closure  $confirm
     * @return bool
     */
    public function apply(OutputInterface $output, Closure $confirm): bool
    {
        $state = $this->state();
        $previous = [$state['path'] => file_get_contents($state['path'])];

        if (! $state['reject'] && ! vpsManager()->certbot()->createSnakeoilCertificate($output)) {
            return false;
        }

        if (! $this->write($state['path'], $state['content'], $output)) {
            return false;
        }

        if (! vpsManager()->nginx()->reload()) {
            $this->restore($previous, $output, 'The default server has been restored.');
            vpsManager()->nginx()->reload();

            return false;
        }

        // An unknown name gets no certificate, the workers of the reload take over in a moment
        for ($i = 0; $i < 10; $i++) {
            $lines = [];
            exec('timeout 10 openssl s_client -connect 127.0.0.1:443 -servername vpsmanager-check.invalid < /dev/null 2>&1', $lines);
            $certificate = (bool) preg_grep('/BEGIN CERTIFICATE|^subject=/', $lines);

            if (! $state['reject'] || ! $certificate) {
                break;
            }

            usleep(500000);
        }

        if ($state['reject'] && $certificate) {
            $output->writeln('<error>NGINX still sends a certificate to an unknown name.</error>');

            return false;
        }

        $output->writeln($state['reject'] ? 'NGINX refuses TLS of the unknown names.' : 'NGINX answers the unknown names by the snakeoil certificate.');

        return true;
    }
}
