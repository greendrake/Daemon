<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

class Test extends \PHPUnit_Framework_TestCase
{

    public function testIt()
    {
        $path = tempnam(sys_get_temp_dir(), __CLASS__);
        $d = new \DrakeES\Daemon\Example;
        $this->assertFalse($d->hasFinished());
        $d->setPath($path);
        $d->start();
        $this->assertFalse($d->hasFinished());
        $this->assertTrue($d->isRunning());
        $sleep = 1;
        sleep($sleep);
        $d->stop();
        $this->assertFalse($d->isRunning());
        $this->assertTrue($d->hasFinished());
        $this->assertGreaterThanOrEqual($sleep, (int)(file_get_contents($path)));
        unlink($path);
    }

}