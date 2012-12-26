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
     * @var resource
     */
    protected $queue;

    /**
     * Init
     */
    public function __construct($pid)
    {
        $this->pid = $pid;

        $_queue = $this->queue = msg_get_queue($pid);

        $this->on('exit', function () use ($_queue) {
            msg_remove_queue($_queue);
        });
    }

    /**
     * Listen message send to current process
     *
     * @return Process
     */
    public function startListener()
    {
        $current_pid = posix_getpid();
        $queue = false;
        if (msg_queue_exists($current_pid)) {
            $queue = msg_get_queue($current_pid);
        }
        $that = $this;

        register_tick_function(function () use ($that, $queue) {
            if (!$queue || !is_resource($queue) || !msg_stat_queue($queue)) {
                return;
            }

            if (msg_receive($queue, 1, $null, 1024, $msg, true, MSG_IPC_NOWAIT, $error)) {
                $that->emit('message', $msg);
            }
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
        // Check queue and send messages
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
