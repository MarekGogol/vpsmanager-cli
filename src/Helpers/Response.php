<?php

namespace Gogol\VpsManagerCLI\Helpers;

class Response
{
    /**
     * The response type (success or error).
     *
     * @var string|null
     */
    public $type = null;

    /**
     * The response message.
     *
     * @var string|null
     */
    public $message = null;

    /**
     * Additional response data.
     *
     * @var array
     */
    public $data = [];

    /**
     * Set error response.
     *
     * @param  string|null  $message
     * @return $this
     */
    public function error($message): static
    {
        $this->type = 'error';
        $this->message = $message;

        return $this;
    }

    /**
     * Set success response.
     *
     * @param  string|null  $message
     * @return $this
     */
    public function success($message): static
    {
        $this->type = 'success';
        $this->message = $message;

        return $this;
    }

    /**
     * Set message response.
     *
     * @param  string|null  $message
     * @return $this
     */
    public function message($message): static
    {
        return $this->success($message);
    }

    /**
     * Check if response is error.
     *
     * @return bool
     */
    public function isError(): bool
    {
        return $this->is('error');
    }

    /**
     * Check if response is of given type.
     *
     * @param  string  $type
     * @return bool
     */
    public function is($type): bool
    {
        return $this->type == $type;
    }

    /**
     * Return response with data.
     *
     * @param  array  $data
     * @return $this
     */
    public function withData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Write message into console output, if output is available.
     *
     * @param  bool  $separator
     * @param  bool  $with_error
     * @return $this
     */
    public function writeln($separator = false, $with_error = false): static
    {
        if (! $this->message || ($this->isError() && $with_error === false)) {
            return $this;
        }

        if (! $output = vpsManager()->getOutput()) {
            return $this;
        }

        $output->writeln($this->message.($separator ? "\n" : ''));

        return $this;
    }

    /**
     * Return error with wrong domain name.
     *
     * @return $this
     */
    public function wrongDomainName(): static
    {
        return $this->error('Domain name is not in valid format.');
    }
}
