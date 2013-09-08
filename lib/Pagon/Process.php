<?php

namespace Pagon;

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
    public $manager;

    /**
     * @var resource
     */
    public $queue;

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
     */
    public function __construct(ChildProcess $child_process, $pid, $ppid)
    {
        $this->pid = $pid;
        $this->ppid = $ppid;
        $this->manager = $child_process;

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

        // Init
        $this->emit('init');

        // Set init
        $this->_init = true;

        $self = $this;

        // Create tick function register to master
        $tick = function () use ($self) {
            // Check queue
            if (!$self->manager || !$self->queue) return;

            if ($self->isMaster()) {
                /**
                 * In master process, listen current process queue to send child
                 */
                if (!msg_queue_exists($self->pid)) return;
                $self->queue = msg_get_queue($self->pid);
            } else {
                /**
                 * In sub process, listen the parent process to send master
                 */
                if (!msg_queue_exists($self->ppid)) return;
                $self->queue = msg_get_queue($self->ppid);
            }

            $self->emit('listen');
            $self->listened = true;
        };

        // Register to tick
        $this->manager->on('tick', $tick);

        // When child process exit remove this
        $this->on('exit', function () use ($self, $tick) {
            $self->manager->removeListener('tick', $tick);
        });

        return $this;
    }

    /**
     * Listen for receive message
     */
    public function listen()
    {
        $this->manager->listen();
        return $this;
    }

    /**
     * Register the runner and options for delay run
     *
     * @param \Closure $runner
     * @param array    $options
     * @return $this
     */
    public function register(\Closure $runner, $options = array())
    {
        $this->runner = $runner;
        $this->options = $options;
        return $this;
    }

    /**
     * Run
     */
    public function run()
    {
        if ($this->_init) throw new \RuntimeException("Process has been initialized, can not run.");

        $this->emit('run');

        $this->manager->parallel($this);
    }

    /**
     * Join for wait process exit
     */
    public function wait()
    {
        $this->manager->wait();
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
        if ($this->queue && is_resource($this->queue) && msg_stat_queue($this->queue)) {
            return msg_send($this->queue, 1, array(
                'from' => $this->isMaster() ? $this->ppid : $this->pid,
                'to'   => $this->isMaster() ? $this->pid : $this->ppid,
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
     * Is init?
     *
     * @return bool
     */
    public function isInit()
    {
        return $this->_init;
    }

    /**
     * Is exit?
     *
     * @return bool
     */
    public function isExit()
    {
        return $this->status !== null;
    }

    /**
     * Is master?
     *
     * @return bool
     */
    public function isMaster()
    {
        return $this->manager->isMaster();
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
        //$this->manager = null;
        $this->queue = null;
        $this->listeners = array();
    }
}