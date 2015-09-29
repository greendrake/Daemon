<?php

namespace DrakeES\Daemon;

class Example {

    use Daemon;

    private $cycles = 0;
    private $path;

    protected function payload()
    {
        $this->cycles++;
    }

    public function setPath($path)
    {
        $this->path = $path;
    }

    protected function onBeforeStop()
    {
        file_put_contents($this->path, $this->cycles);
    }

}