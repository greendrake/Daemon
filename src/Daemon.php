<?php

namespace DrakeES\Daemon;

trait Daemon {

    private $pidFilePath = null;
    
    private $keepGoing = true;

    protected $oneCycleOnly = false;

    private $tickPeriod = null;
    private $isBackground = false;
    private $childSignalHandlerBound = false;

    private $hasWaitedForPidFile = false;

    // Where the daemon is started by calling start(), in most cases,
    // we do not need the daemon to proceed executing whatever logic is underneath that start() call.
    // Sometimes it may be needed, though.
    protected function shouldExitOnceDone()
    {
        return true;
    }

    public function start()
    {
        if ($pid = $this->getPid()) {
            return $pid;
        }
        if (!$this->childSignalHandlerBound) {
            // This is required for the signal handler to execute
            declare(ticks=1);
            // When child process exist, it sends the parent process SIGCHLD signal
            // Handling it properly is required to avoid the exited children becoming zombies
            pcntl_signal(SIGCHLD, array($this, 'childSignalHandler'));
            $this->childSignalHandlerBound = true;
        }
        $pid = pcntl_fork();
        if ($pid == -1) {
            throw new \Exception("Couldn not fork");
        } else if ($pid) {
            $this->onAfterFork();
            $this->writePid($pid);
            return $pid;
        } else {
            posix_setsid();
            $this->isBackground = true;
            $this->onAfterFork();
            $pid = null;
            pcntl_signal(SIGTERM, array($this, 'sigHandler'));
            $e = false;
            $M = 1000000;
            $mul = $M / $this->getTickPeriodMultiplier();
            while ($this->keepGoing) {
                $e = false;
                $cycleStart = microtime(true);
                try {
                    $this->payload();
                } catch (\Exception $e) {
                    if ($this->getConfigValue('stop-on-error', true)) {
                        $this->keepGoing = false;
                        break;
                    }
                }
                if ($this->oneCycleOnly) {
                    break;
                }
                // Sleep the rest of tick time if left any.
                // Need to yeild microseconds in here at all times:
                $tickTimeLeft = $mul * ($this->getTickPeriod() - $M * (microtime(true) - $cycleStart));
                if ($tickTimeLeft > 0) {
                    usleep($tickTimeLeft);
                }
            }
            $this->onBeforeStop();
            $this->cleanup();
            if ($e) {
                throw $e;
            }
            // Avoid executing whatever logic was following start() where it was called
            // because this process exists solely for executing the daemon logic.
            if ($this->shouldExitOnceDone()) {
                exit;
            }
        }
    }

    protected function getTickPeriodMultiplier()
    {
        return 1000000; // By default, tick period is configured in microseconds. Override this if needed, e.g. return "1" if you need seconds instead.
    }

    public function stop()
    {
        if (!($pid = $this->getPid())) {
            // Not running - nothing to stop
            return;
        }
        if (!self::term($pid, $this->getConfigValue('kill-wait', 7))) {
            throw new \Exception('Failed to kill process ' . $pid);
        }
    }

    public function restart()
    {
        $this->stop();
        $this->start();
    }
    
    private function getPidFilePath()
    {
        if ($this->pidFilePath === null) {
            $this->pidFilePath = $this->getConfigValue('pid-file', tempnam(sys_get_temp_dir(), 'drakees-daemon-pid-' . str_replace('\\', '_', get_class($this)) . '-' . date('Y-m-d-H-i-s')));
        }
        return $this->pidFilePath;
    }

    protected function cleanup()
    {
        return unlink($this->getPidFilePath());
    }
    
    private function hasPidBeenWrittenInFile()
    {
        return file_exists($file = $this->getPidFilePath()) && file_get_contents($file) !== '';
    }

    protected function writePid($pid)
    {
        return file_put_contents($this->getPidFilePath(), $pid);
    }

    protected function retrievePid()
    {
        if ($this->pidFilePath === null) {
            return;
        }
        if (!($exists = $this->hasPidBeenWrittenInFile()) && !$this->isBackground() && !$this->hasWaitedForPidFile) {
            // If we are in the outer process and this is the first time we're asking for the PID,
            // it may have not been created yet. Allow some time before jumping to conclusions:
            $attempts = 0;
            do {
                usleep(1000);
                $attempts++;
            } while (
                        !($exists = $this->hasPidBeenWrittenInFile())
                            &&
                        $attempts < 50
                    );
            $this->hasWaitedForPidFile = true;
        }
        return $exists ? (int)(file_get_contents($this->getPidFilePath())) : null;
    }
    
    // Returns PID if daemon is running, or FALSE otherwise
    public function getPid()
    {
        return ($pid = $this->retrievePid()) && posix_kill($pid, 0) ? $pid : false;
    }

    public function isRunning()
    {
        return $this->getPid() !== false;
    }

    public function hasFinished()
    {
        return $this->retrievePid() && !$this->isRunning();
    }

    public function childSignalHandler($signo, $pid = null, $status = null)
    {
        pcntl_waitpid($pid ? $pid : -1, $status, WNOHANG);
    }

    protected function isBackground()
    {
        return $this->isBackground;
    }
        
    public function sigHandler($signo)
    {
        switch($signo) {
            case SIGTERM:
                $this->keepGoing = false;
            break;
            default:
            
            break;
        }
    }
    
    protected function getTickPeriod()
    {
        if ($this->tickPeriod === null) {
            $this->tickPeriod = (int)($this->getConfigValue('tick-period', $this->getTickPeriodMultiplier()));
        }
        return $this->tickPeriod;
    }

    private static function term($pid, $waitTime = 1)
    {
        posix_kill($pid, SIGTERM);
        $count = 0;
        $max = null;
        // Wait until the process actually vanishes
        // and return false if it doesn't within the given time
        while (posix_kill($pid, 0)) {
            $count++;
            if ($max === null) {
                $waitTime = $waitTime * 1000000;
                $sleep = 100000;
                $max = round($waitTime / $sleep);
            }
            if ($count > $max) {
                return false;
            }
            usleep($sleep);
        }
        return true;
    }

    // Some logic might be needed here e.g. resetting PDO connections
    protected function onAfterFork()
    {

    }

    // The methods below are to be implemented in the holder class where required

    protected function getConfigValue($key, $default = null)
    {
        return $default;
    }

    protected function log($text)
    {
    }

    protected function payload()
    {
    }
    
    // Called by the background process before it quits
    protected function onBeforeStop()
    {
    }

}