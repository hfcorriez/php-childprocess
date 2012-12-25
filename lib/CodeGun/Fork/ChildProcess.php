<?php

namespace CodeGun\Fork;

declare(ticks = 1) ;

class ChildProcess extends EventEmitter
{
    /**
     * @var int pid of Current process
     */
    public $pid;

    /**
     * @var Process
     */
    protected $process;

    /**
     * @var Process[]
     */
    protected static $children;

    /**
     * Init
     */
    public function __construct()
    {
        $this->pid = posix_getpid();
        $this->process = new Process($this->pid);
        $this->registerSigHandlers();
    }

    /**
     * Attempt to fork a child process from the parent to run a job in.
     *
     * @param callable $callback
     * @return Process
     * @throws \RuntimeException
     */
    public function parallel($callback)
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork child process.');
        } else if ($pid) {
            self::$children[$pid] = new Process($pid);
            return self::$children[$pid];
        } else {
            if (is_callable($callback)) {
                call_user_func_array($callback, array($this->process));
            } else {
                throw new \RuntimeException('Only callable can be run in parallel space');
            }
            exit;
        }
    }

    /**
     * Spawn the command
     *
     * @param string $cmd
     * @return Process
     * @throws \RuntimeException
     */
    public function spawn($cmd)
    {
        $guid = uniqid();

        $files = array(
            "/tmp/$guid.in",
            "/tmp/$guid.out",
            "/tmp/$guid.err",
        );

        // Make pipes
        foreach ($files as $file) {
            posix_mkfifo($file, 0666);
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork child process.');
        } else if ($pid) {
            // Make file descriptor
            $pipes = array();
            foreach ($files as $i => $file) {
                $pipes[] = fopen($file, $i > 0 ? 'r' : 'w');
            }

            // Save process
            self::$children[$pid] = $child = new Process($pid);

            // Remove file when exit
            $child->on('exit', function () use (&$files, &$pipes) {
                foreach ($files as $file) {
                    unlink($file);
                }
                $files = $pipes = array();
            });

            // Register tick function to check streams
            register_tick_function(function () use ($pipes, $child) {
                $readers = $pipes;

                // Select the streams
                if (!$readers || stream_select($readers, $null, $null, 0) <= 0) return;

                // Events name
                $events = array('stdin', 'stdout', 'stderr');

                // Check which reader selected and emit event
                foreach ($readers as $reader) {
                    if (!is_resource($reader)) continue;
                    $index = array_search($reader, $pipes);
                    if (!feof($reader) && ($_buffer = fread($reader, 1024))) {
                        $child->emit($events[$index], $_buffer);
                    }
                }
            });

            return $child;
        } else {
            $pipes = array();

            // Make file descriptors for proc_open()
            $fd = array();
            foreach ($files as $i => $file) {
                $fd[] = array('file', $file, $i > 0 ? 'w' : 'r');
            }

            // Open pipe to run process
            $resource = proc_open($cmd, $fd, $pipes);

            if (!is_resource($resource)) {
                throw new \RuntimeException('Can not run ' . $cmd . ' using pipe open');
            }

            // Close all pipes
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }

            proc_close($resource);
            exit;
        }

    }

    /**
     * Register signal handlers that a worker should respond to.
     *
     */
    protected function registerSigHandlers()
    {
        pcntl_signal(SIGTERM, array($this, 'signalHandler'));
        pcntl_signal(SIGINT, array($this, 'signalHandler'));
        pcntl_signal(SIGQUIT, array($this, 'signalHandler'));
        pcntl_signal(SIGUSR1, array($this, 'signalHandler'));
        pcntl_signal(SIGUSR2, array($this, 'signalHandler'));
        pcntl_signal(SIGCONT, array($this, 'signalHandler'));
        pcntl_signal(SIGPIPE, array($this, 'signalHandler'));
        pcntl_signal(SIGCHLD, array($this, 'signalHandler'));
    }

    /**
     * Control signals
     *
     * @param $signal
     */
    public function signalHandler($signal)
    {
        $this->emit($signal);
        switch ($signal) {
            case SIGTERM:
            case SIGINT:
                $this->emit('shutdown');
                break;
            case SIGQUIT:
                $this->emit('quit');
                break;
            case SIGCHLD:
                while (($pid = pcntl_wait($status)) > 0) {
                    self::$children[$pid]->status = $status;
                    self::$children[$pid]->emit('exit', $status);
                }
        }
    }
}
