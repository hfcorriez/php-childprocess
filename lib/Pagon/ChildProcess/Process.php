<?php

namespace Pagon\ChildProcess;

use Pagon\EventEmitter;

declare(ticks = 1);

class Process extends EventEmitter
{
    /**
     * @var int Pid of parent process
     */
    public $ppid;

    /**
     * @var int Pid of this process
     */
    public $pid;

    /**
     * @var int Exit code of this process
     */
    public $status;

    /**
     * @var ChildProcess
     */
    public $parent;

    /**
     * @var resource
     */
    public $queue;

    /**
     * @var bool If master?
     */
    public $master = true;

    /**
     * @var bool
     */
    public $prepared = false;

    /**
     * @var bool
     */
    public $listened = false;

    /**
     * @var \Closure
     */
    public $runner;

    /**
     * @var Array runner options
     */
    public $options;

    /**
     * @var Boolean
     */
    protected $_init = false;


    /**
     * @param ChildProcess $child_process
     * @param int          $pid
     * @param int          $ppid
     * @param bool         $master
     */
    public function __construct(ChildProcess $child_process, $pid, $ppid, $master = true)
    {
        $this->pid = $pid;
        $this->ppid = $ppid;
        $this->master = $master;
        $this->parent = $child_process;

        if ($this->pid) {
            // If pid exists, init directly
            $this->init($pid);
        }
    }

    /**
     * Init
     */
    public function init($pid = null)
    {
        // Check init, init only can be called once.
        if ($this->_init) {
            throw new \RuntimeException('Process has been initialized');
        }

        // Check pid
        if (!$pid && !$this->pid) {
            throw new \RuntimeException('Process has not pid');
        }

        // Set pid
        $this->pid = $pid;

        // Set init
        $this->_init = true;

        $that = $this;

        // Create tick function register to master
        $tick = function () use ($that) {
            // Check queue
            if ($that->queue) return;

            if (!msg_queue_exists($that->pid)) return;

            if ($that->master) {
                $that->queue = msg_get_queue($that->pid);
            } else {
                $that->queue = msg_get_queue($that->ppid);
            }

            $that->emit('listen');
            $that->listened = true;
        };

        // Register to tick
        $this->parent->on('tick', $tick);

        // When child process exit remove this
        $this->on('exit', function () use ($that, $tick) {
            $that->parent->removeListener('tick', $tick);
        });

        return $this;
    }

    /**
     * Register the runner and options for delay run
     *
     * @param \Closure $runner
     * @param array    $options
     */
    public function register(\Closure $runner, $options = array())
    {
        $this->runner = $runner;
        $this->options = $options;
    }

    /**
     * Run
     */
    public function run()
    {
        if ($this->_init) throw new \RuntimeException("Process has been initialized");

        $this->emit('run');

        $this->parent->parallel($this);
    }

    /**
     * Join for wait process exit
     */
    public function join()
    {
        $this->run();
        $break = false;

        $this->on('exit', function () use (&$break) {
            $break = true;
        });

        while (!$break) {
        }
    }

    /**
     * Send msg to child process
     *
     * @param mixed $msg
     * @return bool
     */
    public function send($msg)
    {
        // Check queue and send messages
        if (is_resource($this->queue) && msg_stat_queue($this->queue)) {
            return msg_send($this->queue, 1, array(
                'from' => $this->master ? $this->ppid : $this->pid,
                'to'   => $this->master ? $this->pid : $this->ppid,
                'body' => $msg
            ), true, false, $error);
        }
        return false;
    }

    /**
     * Kill process
     *
     * @param int $signal
     * @return bool
     */
    public function kill($signal = SIGKILL)
    {
        return posix_kill($this->pid, $signal);
    }

    /**
     * Shutdown
     *
     * @param int $status
     */
    public function shutdown($status = 0)
    {
        if ($this->status === null) {
            $this->status = $status;
            $this->emit('exit', $status);
        }
    }

    /**
     * @return bool
     */
    public function isExit()
    {
        return $this->status !== null;
    }

    /**
     * If master?
     *
     * @return bool
     */
    public function isMaster()
    {
        return $this->master;
    }

    /**
     * Is prepared to receive?
     *
     * @return bool
     */
    public function isPrepared()
    {
        return $this->prepared;
    }

    /**
     * Is listened?
     *
     * @return bool
     */
    public function isListened()
    {
        return $this->listened;
    }

    /**
     * Generate a string representation of this worker.
     *
     * @return string String identifier for this worker instance.
     */
    public function __toString()
    {
        return $this->pid;
    }

    /**
     * Userland solution for memory leak
     */
    function __destruct()
    {
        unset($this->parent, $this->listeners);
    }
}
