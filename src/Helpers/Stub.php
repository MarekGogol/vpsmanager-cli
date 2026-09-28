<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

class Stub extends Application
{
    /**
     * The stub content.
     *
     * @var string
     */
    protected $content = '';

    /**
     * Create a new stub instance.
     *
     * @param  string|null  $name
     * @return void
     */
    public function __construct($name = null)
    {
        if ($name) {
            $this->load($name);
        }
    }

    /**
     * Render the stub as a string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->render();
    }

    /**
     * Get the path of the given stub file.
     *
     * @param  string  $name
     * @return string
     */
    public function getStubPath($name): string
    {
        return __DIR__.'/../Stub/'.$name;
    }

    /**
     * Load the content of the stub.
     *
     * @param  string  $name
     * @return $this
     */
    public function load($name): static
    {
        $this->content = file_get_contents($this->getStubPath($name));

        return $this;
    }

    /**
     * Append the content of another stub file.
     *
     * @param  string  $name
     * @param  string|null  $separator
     * @return $this
     */
    public function addFile($name, $separator = null): static
    {
        $this->content = $this->content.($this->content ? ($separator ?: "\n") : '').file_get_contents($this->getStubPath($name));

        return $this;
    }

    /**
     * Replace the binding in the stub content.
     *
     * @param  string  $key
     * @param  string  $value
     * @return $this
     */
    public function replace($key, $value): static
    {
        $this->content = str_replace($key, (string) $value, $this->content);

        return $this;
    }

    /**
     * Append a line at the end of the stub.
     *
     * @param  string  $line
     * @return $this
     */
    public function addLine($line): static
    {
        $this->content .= "\n".$line;

        return $this;
    }

    /**
     * Prepend a line at the beginning of the stub.
     *
     * @param  string  $line
     * @return $this
     */
    public function addLineBefore($line): static
    {
        $this->content = $line."\n".$this->content;

        return $this;
    }

    /**
     * Render the stub content.
     *
     * @return string
     */
    public function render(): string
    {
        return $this->content;
    }

    /**
     * Save the stub content into the given path.
     *
     * @param  string  $path
     * @return int|false
     */
    public function save($path): int|false
    {
        return file_put_contents($path, $this->render());
    }
}
