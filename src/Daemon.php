<?php

namespace DrakeES\Daemon;

trait Daemon {

    private $pidFilePath = null;
    private $keepGoing;
    private $tickPeriod = null;
    private $isBackground = false;
    private $childSignalHandlerBound = false;

    private $hasWaitedForPid = false;

    public function start()
    {
        if ($pid = $this->getPid(true)) {
            return;
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
            
        } else {
            posix_setsid();
            $this->isBackground = true;
            $pid = null;
            $this->keepGoing = true;
            pcntl_signal(SIGTERM, array($this, 'sigHandler'));
            while ($this->keepGoing) {
                $cycleStart = microtime(true);
                $current_pid = getmypid(); // PID can change while running!
                if ($current_pid !== $pid) {
                    $pid = $current_pid;
                    if (!$this->writePid($pid)) {
                        throw new \Exception("Can't write to {$this->pidFile}");
                    }
                }
                $payload = false;
                try {
                    $payload = $this->payload();
                } catch (\Exception $e) {
                    $this->log((string)$e);
                    if ($this->getConfigValue('stop-on-error', true)) {
                        $this->keepGoing = false;
                        break;
                    }
                }
                // Sleep the rest of tick time if left any
                $tickTimeLeft = $this->getTickPeriod() - 1000000 * (microtime(true) - $cycleStart);
                if ($tickTimeLeft > 0) {
                    usleep($tickTimeLeft);
                }
            }
            $this->onBeforeStop();
            $this->removePidFile();
            // Avoid executing whatever logic was following start() where it was called
            // because this process exists solely for executing the daemon logic.
            exit;
        }
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
    
    private function removePidFile()
    {
        return unlink($this->getPidFilePath());
    }
    
    private function writePid($pid)
    {
        $file = $this->getPidFilePath();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            \Xcms\File::mkdir($dir, true);
        }
        return file_put_contents($file, $pid);
    }

    private function hasPidBeenWritten()
    {
        $file = $this->getPidFilePath();
        if (!file_exists($file)) {
            return;
        }
        return file_get_contents($file) !== '';
    }
    
    // Returns PID if daemon is running, or FALSE otherwise
    public function getPid($doNotWait = false)
    {
        if (!($exists = $this->hasPidBeenWritten()) && !$doNotWait && !$this->isBackground() && !$this->hasWaitedForPid) {
            // If we are in the outer process and this is the first time we're asking for the PID,
            // it may have not been created yet. Allow some time before jumping to conclusions:
            $attempts = 0;
            do {
                usleep(1000);
                $attempts++;
            } while (
                        !($exists = $this->hasPidBeenWritten())
                            &&
                        $attempts < 200
                    );
            $this->hasWaitedForPid = true;
        }
        if (!$exists) {
            return false;
        }
        $pid = file_get_contents($this->getPidFilePath());
        if (posix_kill($pid, 0)) {
            return $pid;
        } else {
            if (!$this->removePidFile()) {
                throw new \Exception("Daemon is not running and I can't remove its pidfile {$file}");
            }
            return false;
        }
    }

    public function isRunning()
    {
        return $this->getPid() !== false;
    }

    private function childSignalHandler($signo, $pid = null, $status = null)
    {
        pcntl_waitpid($pid ? $pid : -1, $status, WNOHANG);
    }

    protected function isBackground()
    {
        return $this->isBackground;
    }
        
    private function sigHandler($signo)
    {
        switch($signo) {
            case SIGTERM:
                $this->keepGoing = false;
            break;
            default:
            
            break;
        }
    }
    
    private function getPidFilePath()
    {
        if ($this->pidFilePath === null) {
            $this->pidFilePath = $this->getConfigValue('pid-file', tempnam(sys_get_temp_dir(), 'drakees-daemon-pid-' . str_replace('\\', '_', get_class($this)) . '-' . date('Y-m-d-H-i-s')));
        }
        return $this->pidFilePath;
    }
    
    protected function getTickPeriod()
    {
        if ($this->tickPeriod === null) {
            $this->tickPeriod = (int)($this->getConfigValue('tick-period', 1000000)); // microseconds
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