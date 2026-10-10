<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

/**
 * Router or load balancer in front of the server (nginx_is_proxied). Its firewall sees the addresses of the visitors,
 * the firewall of this server only the router. NGINX of this server finds the scanners and sends the blocked
 * requests over syslog to the router, fail2ban of the router bans them for all servers behind it. This server
 * manages the router over SSH, the router needs no VPS Manager.
 */
class Router extends Application
{
    /**
     * Port of syslog on the router.
     */
    const SYSLOG_PORT = 514;

    /**
     * Check if the server is behind a router (HTTPS listeners with proxy_protocol).
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (bool) $this->config('nginx_is_proxied');
    }

    /**
     * SSH destination of the router, monitor_router of the configuration or root at the default gateway.
     *
     * @return string|null e.g. ssh://root@192.168.1.1:1000
     */
    public function getDestination(): ?string
    {
        if ($destination = $this->config('monitor_router')) {
            return $destination;
        }

        exec('ip -4 route show default 2> /dev/null', $output);

        return preg_match('/default via (\S+)/', implode("\n", $output), $matches) ? 'ssh://root@'.$matches[1] : null;
    }

    /**
     * Address of the router in the network of this server, NGINX sends the blocked requests there.
     *
     * @return string|null
     */
    public function getAddress(): ?string
    {
        if (! ($destination = $this->getDestination())) {
            return null;
        }

        $host = parse_url(str_contains($destination, '://') ? $destination : 'ssh://'.$destination, PHP_URL_HOST);

        return filter_var($host, FILTER_VALIDATE_IP) ? $host : (gethostbyname($host) ?: null);
    }

    /**
     * Network of this server which contains the router, the router accepts syslog only from it.
     *
     * @return string|null e.g. 192.168.1.0/24
     */
    public function getNetwork(): ?string
    {
        if (! ($router = $this->getAddress())) {
            return null;
        }

        exec('ip -o -4 addr show 2> /dev/null', $output);

        foreach ($output as $line) {
            if (! preg_match('#inet (\d+\.\d+\.\d+\.\d+)/(\d+)#', $line, $matches)) {
                continue;
            }

            $mask = (int) $matches[2] === 0 ? 0 : (~0 << (32 - (int) $matches[2])) & 0xFFFFFFFF;

            if ((ip2long($matches[1]) & $mask) === (ip2long($router) & $mask)) {
                return long2ip(ip2long($matches[1]) & $mask).'/'.$matches[2];
            }
        }

        return null;
    }

    /**
     * fail2ban of the router.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\Fail2ban
     */
    public function fail2ban(): Fail2ban
    {
        return $this->fail2banHelper()->onRouter($this->getDestination());
    }

    /**
     * The fail2ban helper of this server.
     *
     * @return \Gogol\VpsManagerCLI\Helpers\Fail2ban
     */
    protected function fail2banHelper(): Fail2ban
    {
        return vpsManager()->fail2ban();
    }

    /**
     * Files of syslog on the router, path => content: the input of the blocked requests and their rotation.
     *
     * @return array
     */
    public function getSyslogFiles(): array
    {
        $resources = __DIR__.'/../Resources/router';

        $replace = [
            '{address}' => $this->getAddress(),
            '{port}' => self::SYSLOG_PORT,
            '{network}' => $this->getNetwork(),
            '{scanners_log}' => Fail2ban::ROUTER_SCANNERS_LOG,
            '{auth_log}' => Fail2ban::ROUTER_AUTH_LOG,
        ];

        return [
            '/etc/rsyslog.d/vpsmanager.conf' => strtr(file_get_contents($resources.'/rsyslog.conf'), $replace),
            '/etc/logrotate.d/vpsmanager' => strtr(file_get_contents($resources.'/logrotate.conf'), $replace),
        ];
    }

    /**
     * Check if rsyslog runs on the router instead of syslogd of BusyBox, which does not receive logs of the network.
     *
     * @return bool
     */
    public function hasSyslog(): bool
    {
        return $this->fail2ban()->run('command -v rsyslogd > /dev/null && rc-service rsyslog status > /dev/null 2>&1') === 0;
    }

    /**
     * Install rsyslog on the router (Alpine) and replace syslogd of BusyBox by it. The default configuration
     * of rsyslog keeps the system logs in /var/log/messages.
     *
     * @return bool
     */
    public function installSyslog(): bool
    {
        $command = '(command -v rsyslogd > /dev/null || apk add --no-cache rsyslog) 2>&1'
            .' && rc-update del syslog boot > /dev/null 2>&1; rc-service syslog stop > /dev/null 2>&1;'
            .' rc-update add rsyslog boot > /dev/null 2>&1 && rc-service rsyslog restart 2>&1';

        if ($this->fail2ban()->run($command, $output) !== 0) {
            $this->response()->error(implode("\n", array_slice($output, -10)))->writeln();

            return false;
        }

        return true;
    }

    /**
     * Restart rsyslog of the router, it reads changed files of /etc/rsyslog.d.
     *
     * @return bool
     */
    public function restartSyslog(): bool
    {
        return $this->fail2ban()->run('rsyslogd -N1 > /dev/null 2>&1 && rc-service rsyslog restart > /dev/null 2>&1') === 0;
    }

    /**
     * Syslog destination of NGINX for the blocked requests of the given log, null when the server is not behind a router.
     *
     * @param  string  $tag  vpsm_scanners or vpsm_auth
     * @return string|null
     */
    public function getNginxSyslog(string $tag): ?string
    {
        if (! $this->isEnabled() || ! ($address = $this->getAddress())) {
            return null;
        }

        return 'syslog:server='.$address.':'.self::SYSLOG_PORT.',tag='.$tag.',nohostname';
    }
}
