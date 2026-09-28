<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

class SSH extends Application
{
    /**
     * Check if SSH daemon configuration is valid.
     *
     * @return bool
     */
    public function test(): bool
    {
        exec('sshd -t 2> /dev/null', $output, $return_var);

        // If sshd configuration has errors, run the test again to print them
        if ($return_var != 0) {
            exec('sshd -t');
        }

        return $return_var == 0;
    }

    /**
     * Restart SSH service.
     *
     * @param  bool  $test_before
     * @return bool
     */
    public function restart($test_before = true): bool
    {
        if ($test_before === true && ! $this->test()) {
            return false;
        }

        exec('service ssh restart', $output, $return_var);

        return $return_var == 0;
    }

    /**
     * Test SSH configuration and restart SSH service with console output.
     *
     * @return void
     */
    public function rebootSSH(): void
    {
        if (! $this->test()) {
            $this->response()
                ->message('<error>SSH configuration is not correct. Please fix SSH configuration and restart SSH manually.</error>')
                ->writeln();

            return;
        }

        if ($this->restart(false)) {
            $this->response()
                ->success('<comment>SSH has been successfully restarted.</comment>')
                ->writeln();
        } else {
            $this->response()
                ->message('<error>SSH could not be restarted. Please try again manually.</error>')
                ->writeln();
        }
    }
}
