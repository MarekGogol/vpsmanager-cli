<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

/*
 * Supervisor programs of one hosting are stored together in /etc/supervisor/conf.d/{domain}.conf,
 * each program in its own [program:name] section.
 */
class Supervisor extends Application
{
    /**
     * Directory with supervisor configurations.
     *
     * @var string
     */
    protected string $confPath = '/etc/supervisor/conf.d';

    /**
     * Determine if supervisor is installed.
     *
     * @return bool
     */
    public function isInstalled(): bool
    {
        exec('command -v supervisorctl', $output, $return_var);

        return $return_var === 0 && is_dir($this->confPath);
    }

    /**
     * Get the configuration path of the given hosting.
     *
     * @param  string  $domain
     * @return string
     */
    public function getConfPath(string $domain): string
    {
        return $this->confPath.'/'.$this->toUserFormat($domain).'.conf';
    }

    /**
     * Get program sections of the given hosting, keyed by the program name.
     *
     * @param  string  $domain
     * @return array
     */
    public function getPrograms(string $domain): array
    {
        $path = $this->getConfPath($domain);

        return file_exists($path) ? $this->parseSections(file_get_contents($path)) : [];
    }

    /**
     * Get program sections of all configurations, keyed by the program name.
     *
     * @return array
     */
    public function getAllPrograms(): array
    {
        $programs = [];

        foreach (glob($this->confPath.'/*.conf') ?: [] as $path) {
            $programs = array_merge($programs, $this->parseSections(file_get_contents($path)));
        }

        return $programs;
    }

    /**
     * Determine if the program exists in the hosting configuration.
     *
     * @param  string  $domain
     * @param  string  $program
     * @return bool
     */
    public function exists(string $domain, string $program): bool
    {
        return array_key_exists($program, $this->getPrograms($domain));
    }

    /**
     * Add or replace the program section in the hosting configuration and apply it.
     *
     * @param  string  $domain
     * @param  string  $program
     * @param  string  $section
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function save(string $domain, string $program, string $section): Response
    {
        $programs = $this->getPrograms($domain);
        $programs[$program] = trim($section);

        if (! $this->saveSections($domain, $programs)) {
            return $this->response()->error('Supervisor configuration <comment>'.$this->getConfPath($domain).'</comment> could not be saved.');
        }

        if (! $this->update()) {
            return $this->response()->error('Supervisor configuration has been saved, but supervisor could not be updated. Please run <comment>supervisorctl update</comment> manually.');
        }

        // Update does not restart a program with unchanged configuration
        $this->restart($program);

        return $this->response()->success('Supervisor program <comment>'.$program.'</comment> has been successfully started.');
    }

    /**
     * Remove the program section from the hosting configuration and stop it.
     *
     * @param  string  $domain
     * @param  string  $program
     * @return \Gogol\VpsManagerCLI\Helpers\Response
     */
    public function remove(string $domain, string $program): Response
    {
        $programs = $this->getPrograms($domain);

        if (! array_key_exists($program, $programs)) {
            return $this->response()->error('Supervisor program <comment>'.$program.'</comment> does not exist.');
        }

        unset($programs[$program]);

        if (! $this->saveSections($domain, $programs)) {
            return $this->response()->error('Supervisor configuration <comment>'.$this->getConfPath($domain).'</comment> could not be saved.');
        }

        // Update stops and removes programs which are no longer configured
        if (! $this->update()) {
            return $this->response()->error('Supervisor configuration has been updated, but supervisor could not be updated. Please run <comment>supervisorctl update</comment> manually.');
        }

        return $this->response()->success('Supervisor program <comment>'.$program.'</comment> has been successfully stopped and removed.');
    }

    /**
     * Remove all programs of the given hosting.
     *
     * @param  string  $domain
     * @return bool
     */
    public function removeAll(string $domain): bool
    {
        if (! file_exists($path = $this->getConfPath($domain))) {
            return true;
        }

        unlink($path);

        return $this->update();
    }

    /**
     * Restart all processes of the given program.
     *
     * @param  string  $program
     * @return bool
     */
    public function restart(string $program): bool
    {
        exec('supervisorctl restart '.escapeshellarg($program.':*').' 2>&1', $output, $return_var);

        return $return_var === 0;
    }

    /**
     * Get the status output of the given program.
     *
     * @param  string  $program
     * @return string
     */
    public function status(string $program): string
    {
        exec('supervisorctl status '.escapeshellarg($program.':*').' 2>&1', $output);

        return implode("\n", $output);
    }

    /**
     * Reload configurations and apply changes.
     *
     * @return bool
     */
    public function update(): bool
    {
        exec('supervisorctl reread 2>&1 && supervisorctl update 2>&1', $output, $return_var);

        return $return_var === 0;
    }

    /**
     * Split the configuration into program sections with their leading comments.
     *
     * @param  string  $conf
     * @return array
     */
    protected function parseSections(string $conf): array
    {
        preg_match_all('/(?:^;[^\n]*\n)*^\[program:([^\]]+)\][\s\S]*?(?=\n(?:;[^\n]*\n)*\[program:|\z)/m', $conf, $matches, PREG_SET_ORDER);

        $sections = [];

        foreach ($matches as $match) {
            $sections[$match[1]] = trim($match[0]);
        }

        return $sections;
    }

    /**
     * Save program sections into the hosting configuration, or remove it when there is no program.
     *
     * @param  string  $domain
     * @param  array  $sections
     * @return bool
     */
    protected function saveSections(string $domain, array $sections): bool
    {
        $path = $this->getConfPath($domain);

        if (count($sections) === 0) {
            return ! file_exists($path) || unlink($path);
        }

        ksort($sections);

        return file_put_contents($path, implode("\n\n", $sections)."\n") !== false;
    }
}
