<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

class SSH extends Application
{
    /*
     * Check if configuration is ok
     */
    public function test()
    {
        exec('sshd -t 2> /dev/null', $output, $return_var);

        //If sshd has error, then test it again with response
        if ($return_var != 0) {
            exec('sshd -t');
        }

        return $return_var == 0 ? true : false;
    }

    /*
     * Restart ssh
     */
    public function restart($test_before = true)
    {
        if ($test_before === true && !$this->test()) {
            return false;
        }

        exec('service ssh restart', $output, $return_var);

        return $return_var == 0 ? true : false;
    }

    public function rebootSSH()
    {
        if ($this->test()) {
            if ($this->restart(false)) {
                $this->response()
                    ->success('<comment>SSH has been successfully restarted.</comment>')
                    ->writeln();
            } else {
                $this->response()
                    ->message('<error>SSH could not be restarted. Please try again manually.</error>')
                    ->writeln();
            }
        } else {
            $this->response()
                ->message('<error>SSH configuration is not correct. Please fix ssh configuration and restart ssh manually.</error>')
                ->writeln();
        }
    }
}

?>
