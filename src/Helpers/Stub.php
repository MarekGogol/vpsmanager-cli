<?php

namespace Gogol\VpsManagerCLI\Helpers;

use Gogol\VpsManagerCLI\Application;

class Stub extends Application
{
    protected $content;

    public function __toString()
    {
        return $this->render();
    }

    public function __construct($name = null)
    {
        if ($name)
            $this->load($name);
    }

    /*
     * Load content of stub
     */
    public function load($name)
    {
        $this->content = file_get_contents(__DIR__ . '/../Stub/' . $name);

        return $this;
    }

    public function addFile($name, $separator = null)
    {
        $this->content = $this->content . ($this->content ? ($separator ?: "\n") : '') . file_get_contents(__DIR__ . '/../Stub/' . $name);

        return $this;
    }

    /*
     * Replace bindings
     */
    public function replace($key, $value)
    {
        $this->content = str_replace($key, $value, $this->content);

        return $this;
    }

    public function addLine($line)
    {
        $this->content .= "\n".$line;

        return $this;
    }

    public function addLineBefore($line)
    {
        $this->content = $line."\n".$this->content;

        return $this;
    }

    public function render()
    {
        return $this->content;
    }

    public function save($path)
    {
        return file_put_contents($path, $this->render());
    }
}
?>