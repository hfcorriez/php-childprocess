<?php

namespace Pagon\ChildProcess;

declare(ticks = 1) ;

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
    public $child_process;

    /**
     * @var resource
     */
    protected $queue;

    /**
     * @var bool If master?
     */
    protected $master = true;

    /**
     * @var bool
     */
    protected $prepared = false;

    /**
     * @var bool
     */
    protected $listened = false;

    /**
     * Init
     */
    public function __construct(ChildProcess $child_process, $pid, $ppid, $master = true)
    {
        $this->pid = $pid;
        $this->ppid = $ppid;
        $this->master = $master;
        $this->child_process = $child_process;

        $that = $this;
        $this->child_process->on('tick', function () use ($that) {
            if ($that->queue) return;
            if (!msg_queue_exists($that->pid)) return;

            if ($that->master) {
                $that->queue = msg_get_queue($that->pid);
            } else {
                $that->queue = msg_get_queue($that->ppid);
            }

            $that->emit('listen');
            $that->listened = true;
        });
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
}
