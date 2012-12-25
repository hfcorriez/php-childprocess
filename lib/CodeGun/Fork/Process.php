<?php

namespace CodeGun\Fork;

declare(ticks = 1) ;

class Process extends EventEmitter
{
    /**
     * @var int Pid of this process
     */
    public $pid;

    /**
     * @var int Exit code of this process
     */
    public $status;

    /**
     * @var resource MsgQueue for send to
     */
    public $queue;

    /**
     * Init
     */
    public function __construct($pid)
    {
        $this->pid = $pid;
    }

    /**
     * Listen the queue
     *
     * @return Process
     */
    public function startListener()
    {
        $queue = msg_get_queue(posix_getpid());
        $that = $this;

        register_tick_function(function () use ($that, $queue) {
            if (!is_resource($queue) || !msg_stat_queue($queue)) {
                return;
            }

            if (msg_receive($queue, 1, $null, 1024, $msg, true, MSG_IPC_NOWAIT, $error)) {
                $that->emit('message', $msg);
            }
        });

        $this->on('exit', function () use ($queue) {
            msg_remove_queue($queue);
        });
        return $this;
    }

    /**
     * Send msg to child process
     *
     * @param mixed $msg
     * @return bool
     */
    public function send($msg)
    {
        if (!$this->queue) {
            $this->queue = msg_get_queue($this->pid);
        }
        if (is_resource($this->queue) && msg_stat_queue($this->queue)) {
            return msg_send($this->queue, 1, $msg, true, false, $error);
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
     * Cover the event register to handle message register
     *
     * @param array|string $event
     * @param callable     $listener
     */
    public function on($event, \Closure $listener)
    {
        parent::on($event, $listener);

        if ($event == 'message') {
            // Automatic start listener when message event create
            $this->startListener();
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
     * Generate a string representation of this worker.
     *
     * @return string String identifier for this worker instance.
     */
    public function __toString()
    {
        return $this->pid;
    }
}
