<?php

namespace Gogol\VpsManagerCLI\Helpers;

class Response
{
    public $type = null;
    public $message = null;
    public $data = [];

    /*
     * Set error response
     */
    public function error($message)
    {
        $this->type = 'error';
        $this->message = $message;

        return $this;
    }

    /*
     * Set success response
     */
    public function success($message)
    {
        $this->type = 'success';
        $this->message = $message;

        return $this;
    }

    /*
     * Message response
     */
    public function message($message)
    {
        return $this->success($message);
    }

    /*
     * Check if response is error
     */
    public function isError()
    {
        return $this->is('error');
    }

    /*
     * Check if response is given type
     */
    public function is($type)
    {
        return $this->type == $type;
    }

    /*
     * Return response with data
     */
    public function withData(array $data)
    {
        $this->data = $data;

        return $this;
    }

    /*
     * If is console output available, write it.
     */
    public function writeln($separator = false, $with_error = false)
    {
        if ( ! $this->message || ($this->isError() && $with_error === false) )
            return $this;

        $separator = ($separator ? "\n" : null);

        vpsManager()->getOutput()->writeln($this->message.$separator);

        return $this;
    }

    /*
     * Return error with wrong domain name
     */
    public function wrongDomainName()
    {
        return $this->error('Domain name is not in valid format.');
    }
}