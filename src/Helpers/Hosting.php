<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

class Hosting extends Application
{
    /*
     * Check if nginx or domain dir exists
     */
    public function checkErrorsBeforeCreate(string $domain, $config)
    {
        $user = $this->toUserFormat($domain);

        if ( ! isValidDomain($domain) )
            return $this->response()->wrongDomainName();

        if ( $this->nginx()->exists($domain) && ! $this->canContinueNginx($user) ){
            return $this->response()->error('Nginx nastavenia pre dómenu '.$user.' už existuju.');
        }

        //Check if can continue with existing user
        if ( $this->server()->existsUser($user) && ! $this->canContinueUser($user) ) {
            return $this->response()->error('LINUX používateľ '.$user.' už existuje.');
        }

        if ( ! $this->php()->isInstalled($config['php_version']) )
            return $this->response()->error('PHP s verziou '.$config['php_version'].' nie je nainštalované.');

        //Check if can continue with existing PHP pool configuration
        if (
            $this->php()->poolExists($domain, $config['php_version'])
            && ! $this->canContinuePool($user, $config['php_version'])
        )
            return $this->response()->error('PHP Pool s názvom '.$domain.'.conf pre verziu PHP '.$config['php_version'].' už existuje.');

        return $this->response();
    }

    /*
     * Check if can continue with existing user
     */
    private function canContinueUser($user)
    {
        $m = vpsManager();

        //If console is not booted properly
        if ( !($m->output && $m->input && $m->helper) )
            return false;

        $question = new \Symfony\Component\Console\Question\ConfirmationQuestion(
            "\n".'<error>User '.$user.' exists already.</error>'."\n".
            'Would you like continue with existing user? (y/N) '
        , false);

        return $m->helper->ask($m->input, $m->output, $question);
    }

    /*
     * Check if can continue with existing nginx
     */
    private function canContinueNginx($user)
    {
        $m = vpsManager();

        //If console is not booted properly
        if ( !($m->output && $m->input && $m->helper) )
            return false;

        $question = new \Symfony\Component\Console\Question\ConfirmationQuestion(
            "\n".'<error>Webhosting '.$user.' already exists and has NGINX configruation.</error>'."\n".
            'Would you like use this existing <comment>'.$this->nginx()->getAvailablePath($user).'</comment> configuration? (y/N) '
        , false);

        return $m->helper->ask($m->input, $m->output, $question);
    }

    /*
     * Check if can continue with existing nginx
     */
    private function canContinuePool($user, $php_version)
    {
        $m = vpsManager();

        //If console is not booted properly
        if ( !($m->output && $m->input && $m->helper) )
            return false;

        $question = new \Symfony\Component\Console\Question\ConfirmationQuestion(
            "\n".'<error>PHP '.$php_version.' Pool for domain name '.$user.' exists already.</error>'."\n".
            'Would you like use this existing <comment>'.$this->php()->getPoolPath($user, $php_version).'</comment> configuration? (y/N) '
        , false);

        return $m->helper->ask($m->input, $m->output, $question);
    }

    /*
     * Get value from hosting
     */
    private function getParam($config, $key, $default = null)
    {
        if ( array_key_exists($key, $config) )
            return $config[$key];

        return $default;
    }

    /**
     * Create new hosting
     * @param  [string] $domain [domain name]
     * @param  [array] $config [hosting configuration]
     * @return [response]
     */
    public function create($domain, array $config = [])
    {
        $config['php_version'] = $this->getParam($config, 'php_version', $this->config('php_version'));

        //Check errors
        if ( ($response = $this->checkErrorsBeforeCreate($domain, $config))->isError() )
            return $response;

        //Create user
        if ( ($response = $this->server()->createUser($domain)->writeln(true))->isError() )
            return $response;

        //Create mysql database
        if ( isset($config['database'])
             && $config['database'] == true
             && ($response = $this->mysql()->createDatabase($domain)->writeln(true))->isError()
         )
            return $response;

        // Create domain directory tree
        if ( ($response = $this->server()->createDomainTree($domain, $config)->writeln())->isError() )
            return $response;

        // Create php pool
        if ( ($response = $this->php()->createPool($domain, $config)->writeln())->isError() )
            return $response;

        //Create nginx host
        if ( ($response = $this->nginx()->createHost($domain, $config)->writeln())->isError() )
            return $response;

        //Test and reboot services
        $this->rebootNginx();
        $this->rebootPHP($config['php_version']);

        return $this->response()->success("\n".'Hosting bol úspešne vytvorený!');
    }

    public function rebootNginx()
    {
        if ( $this->server()->nginx()->test() )
        {
            if ( $this->server()->nginx()->restart(false) ){
                $this->response()->success('<comment>NGINX bol úspešne reštartovaný.</comment>')->writeln();
            } else {
                $this->response()->message('<error>Došlo k chybe pri reštarte služby NGINX. Spustite službu manuálne.</error>')->writeln();
            }
        } else {
            $this->response()->message('<error>Konfigurácia NGINXU nie je správna, preto nie je možné spustiť reštart služby.</error>')->writeln();
        }
    }

    public function rebootPHP($php_version)
    {
        if ( $this->server()->php()->restart($php_version) ){
            $this->response()->success('<comment>PHP '.$php_version.' FPM bolo úspešne reštartované.</comment>')->writeln();
        } else {
            $this->response()->error('<error>Došlo k chybe pri reštarte služby PHP. Spustite službu manuálne.</error>')->writeln(null, true);
        }
    }

    /**
     * Delete hosting
     * @param  string  $domain       [domain name]
     * @param  boolean $remove_data  [set if www data can be deleted]
     * @param  boolean $remove_mysql [set if mysql user and database can me deleted]
     * @return response
     */
    public function remove($domain, $remove_data = false, $remove_mysql = false)
    {
        //Remove nginx
        if ( vpsManager()->nginx()->removeHost($domain) )
            $this->response()->success('<comment>NGINX</comment> <info>host has been successfully disabled and removed.<info>')->writeln();
        else
            $this->response()->error('<error>NGINX host could not be deleted.</error>')->writeln(null, true);

        //Which php fpm versions should be rebooted
        $reboot_php_versions = [];

        //Remove pools from all php versions
        foreach (vpsManager()->php()->getVersions() as $php_version)
        {
            if ( ! vpsManager()->php()->poolExists($domain, $php_version) )
                continue;

            if ( vpsManager()->php()->removePool($domain, $php_version) )
            {
                $reboot_php_versions[] = $php_version;
                $this->response()->success('<comment>PHP '.$php_version.'</comment> <info>pool has been successfuly removed.</info>')->writeln();
            } else {
                $this->response()->message('<error>PHP '.$php_version.' pool could not be deleted.</error>')->writeln();
            }
        }

        //Test and reboot services
        $this->rebootNginx();

        //Rebot all php version from which has been pool removed
        foreach ($reboot_php_versions as $version)
            $this->rebootPHP($version);

        //Remove user
        if ( vpsManager()->server()->deleteUser($domain) )
            $this->response()->success('<info>User</info> <comment>'.$domain.'</comment> <info>has been successfuly removed.</info>')->writeln();
        else
            $this->response()->message('<error>User '.$domain.' could not be deleted.</error>')->writeln();

        //Remove mysql data
        if ( $remove_mysql )
            vpsManager()->mysql()->removeDatabaseWithUser($domain)->writeln(null, true);

        if ( $remove_data === true )
        {
            if ( vpsManager()->server()->deleteDomainTree($domain) )
                $this->response()->success('<info>Data storage</info> <comment>'.vpsManager()->getWebPath($domain).'</comment> <info>has been deleted.</info>')->writeln();
            else
                $this->response()->message('<error>Data storage '.vpsManager()->getWebPath($domain).' could not be deleted.</error>')->writeln();
        }

        return $this->response()->success('<info>Hosting has been successfully removed.</info>');
    }
}
?>