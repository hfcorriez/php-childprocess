<?php

namespace Pagon\ChildProcess;

use Pagon\EventEmitter\EventEmitter;

declare(ticks = 1);

class ChildProcess extends EventEmitter
{
    /**
     * @var int
     */
    public $pid;

    /**
     * @var int
     */
    public $ppid;

    /**
     * @var bool If master process?
     */
    public $master = true;

    /**
     * @var resource
     */
    public $queue;

    /**
     * @var bool Is prepared?
     */
    public $prepared = true;

    /**
     * @var array Options for child process
     */
    protected $options = array(
        'signal_handler' => true
    );

    /**
     * @var Process
     */
    public $process;

    /**
     * @var Process[]
     */
    public $children = array();

    /**
     * @var array Default options for child process
     */
    protected $default_child_options = array(
        'cwd'      => false,
        'user'     => false,
        'env'      => array(),
        'timeout'  => 0,
        'init'     => false,
        'callback' => false
    );

    /**
     * Init
     */
    public function __construct(array $options = array())
    {
        // Save options
        $this->options = $options + $this->options;

        // Prepare resource and data
        $this->ppid = $this->pid = posix_getpid();
        $this->process = new Process($this, $this->pid, $this->ppid, true);
        $this->registerSigHandlers();
        $this->registerShutdownHandlers();
        $this->registerTickHandlers();
    }

    /**
     * Run the closure in parallel space
     *
     * @param callable $closure
     * @param array    $options
     * @throws \RuntimeException
     * @return Process
     */
    public function parallel(\Closure $closure, array $options = array())
    {
        $options = $this->getOptions($options);

        // Build new child
        $child = new Process($this, null, $this->pid);

        // Process init
        if ($options['init'] instanceof \Closure) {
            $options['init']($child);
        }

        // Fork
        $pid = pcntl_fork();

        // Parallel works
        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork child process.');
        } else if ($pid) {
            // Save child process and return
            return $this->children[$pid] = $child->init($pid);
        } else {
            // Child initialize
            $this->childInitialize($options);

            // Support callable
            call_user_func($closure, $this->process);
            exit;
        }
    }

    /**
     * Attempt to fork a child process from the parent to run a job in.
     *
     * @param string $file
     * @param array  $options
     * @throws \RuntimeException
     * @return Process
     */
    public function fork($file, array $options = array())
    {
        $options = $this->getOptions($options);

        // Build new child
        $child = new Process($this, null, $this->pid);

        // Process init
        if ($options['init'] instanceof \Closure) {
            $options['init']($child);
        }

        // Fork
        $pid = pcntl_fork();

        // Parallel works
        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork child process.');
        } else if ($pid) {
            // Save child process and return
            return $this->children[$pid] = $child->init($pid);
        } else {
            // Child initialize
            $this->childInitialize($options);

            // Check file
            if (is_string($file) && is_file($file)) {
                $process = $this->process;
                include($file);
            } else {
                throw new \RuntimeException('Bad file');
            }
            exit;
        }
    }

    /**
     * Spawn the command
     *
     * @param string $cmd
     * @param array  $options
     * @throws \RuntimeException
     * @return Process
     */
    public function spawn($cmd, array $options = array())
    {
        $guid = uniqid();

        // Get options
        $options = $this->getOptions($options);

        $dir = '/tmp';
        if (!empty($options['dir'])) {
            $dir = $options['dir'];
        }

        $files = array(
            "$dir/$guid.in.pipe",
            "$dir/$guid.ou.pipe",
            "$dir/$guid.er.pipe",
        );

        $user = false;
        // Get can be changed user
        if (!empty($options['user'])) {
            $options['user'] = $user = $this->tryChangeUser($options['user']);
        }

        // Make pipes
        foreach ($files as $file) {
            posix_mkfifo($file, 0666);
            // Check if need to change user
            if ($user) {
                // Change owner to changed user
                chown($file, $user['name']);
            }
        }

        // Build new child
        $child = new Process($this, null, $this->pid);

        // Process init
        if ($options['init'] instanceof \Closure) {
            $options['init']($child);
        }

        // Fork
        $pid = pcntl_fork();

        // Parallel works
        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork child process.');
        } else if ($pid) {
            // Save process
            $this->children[$pid] = $child->init($pid);

            // Make file descriptor
            $pipes = array();
            foreach ($files as $i => $file) {
                $pipes[] = fopen($file, $i > 0 ? 'r' : 'w');
            }

            $tick = function () use ($pipes, $child) {
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
            };

            // Register tick function to check streams
            $this->on('tick', $tick);

            // Remove file when exit
            $that = $this;
            $child->on('exit', function () use ($tick, $that, &$files, &$pipes) {
                $that->removeListener('tick', $tick);
                foreach ($files as $file) {
                    unlink($file);
                }
                $files = $pipes = array();
            });

            return $child;
        } else {
            // Child initialize
            $this->childInitialize($options);

            $pipes = array();

            // Make file descriptors for proc_open()
            $fd = array();
            foreach ($files as $i => $file) {
                $fd[] = array('file', $file, $i > 0 ? 'w' : 'r');
            }

            // Open pipe to run process
            $resource = proc_open($cmd, $fd, $pipes, $options['cwd']);

            if (!is_resource($resource)) {
                throw new \RuntimeException('Can not run "' . $cmd . '" using pipe open');
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
     * Make the process daemonize
     *
     * @return ChildProcess
     * @throws \RuntimeException
     */
    public function daemonize()
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork child process.');
        } else if ($pid) {
            exit;
        }

        // Make sub process as session leader
        posix_setsid();

        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork child process.');
        } else if ($pid) {
            exit;
        }

        // Prepare the resource
        $this->prepared = false;
        $pid = posix_getpid();
        $this->ppid = $this->pid;
        $this->pid = $pid;
        $this->queue = null;
        $this->process = new Process($this, $this->pid, $this->ppid, false);
        $this->children = array();
        $this->prepared = true;

        return $this;
    }

    /**
     * If master process?
     *
     * @return bool
     */
    public function isMaster()
    {
        return $this->master;
    }

    /**
     * Register message listener
     */
    public function listen()
    {
        $this->queue = msg_get_queue($this->pid);
    }

    /**
     * Clear children
     *
     * @param Process|int $process
     * @throws \InvalidArgumentException
     */
    public function clear($process)
    {
        if ($process instanceof Process) {
            if (($index = array_search($process, $this->children)) >= 0) {
                unset($this->children[$index]);
            }
        } elseif (is_numeric($process)) {
            if (isset($this->children[$process])) {
                unset($this->children[$process]);
            }
        } else {
            throw new \InvalidArgumentException("Illegal argument");
        }
    }

    /**
     * Init child process
     *
     * @param array $options
     */
    protected function childInitialize(array $options = array())
    {
        $this->prepared = false;
        $this->master = false;
        $pid = posix_getpid();
        $this->ppid = $this->pid;
        $this->pid = $pid;
        $this->queue = null;
        $this->process = new Process($this, $this->pid, $this->ppid, false);
        $this->children = array();
        $this->prepared = true;

        $options = $options + $this->default_child_options;

        $this->childProcessOptions($options);
    }

    /**
     * Process options in child
     *
     * @param $options
     */
    protected function childProcessOptions($options)
    {
        // Process options
        if ($options['cwd']) {
            $this->processChangeUser($options['cwd']);
        }

        // User to be change
        if ($options['user']) {
            $this->processChangeUser($options['user']);
        }

        // Env set
        if ($options['env']) {
            $this->processChangeEnv($options['env']);
        }

        // Env set
        if ($options['timeout']) {
            $this->processSetTimeout($options['timeout']);
        }

        // Support callback
        if ($options['callback'] instanceof \Closure) {
            $options['callback']($this);
        }
    }

    /**
     * Try to change user
     *
     * @param string $user
     * @return array|bool
     * @throws \RuntimeException
     */
    protected function tryChangeUser($user)
    {
        $changed_user = false;
        // Check user can be changed?
        if ($user && posix_getuid() > 0) {
            // Not root
            throw new \RuntimeException('Only root can change user to spwan the process');
        }

        // Check user if exists?
        if ($user && !($changed_user = posix_getpwnam($user))) {
            throw new \RuntimeException('Can not look up user: ' . $user);
        }

        return $changed_user;
    }

    /**
     * Try to set timeout
     *
     * @param int $timeout
     */
    protected function processSetTimeout($timeout)
    {
        $start_time = time();
        $that = $this;
        $this->on('tick', function () use ($timeout, $start_time, $that) {
            if ($start_time + $timeout < time()) $that->shutdown(1);
        });
    }

    /**
     * Process change env
     *
     * @param array $env
     */
    protected function processChangeEnv(array $env)
    {
        foreach ($env as $k => $v) {
            putenv($k . '=' . $v);
        }
    }

    /**
     * Try to change CWD
     *
     * @param string $cwd
     * @throws \RuntimeException
     */
    protected function processChangeCWD($cwd)
    {
        if ($cwd && !chroot($cwd)) {
            throw new \RuntimeException('Can change work dir to ' . $cwd);
        }
    }

    /**
     * Process change user
     *
     * @param string $user
     */
    protected function processChangeUser($user)
    {
        if (is_array($user) || ($user = $this->tryChangeUser($user))) {
            posix_setgid($user['gid']);
            posix_setuid($user['uid']);
        }
    }

    /**
     * Get options
     *
     * @param array $options
     * @return array
     */
    protected function getOptions(array $options = array())
    {
        return $options + $this->default_child_options;
    }

    /**
     * Register signal handlers that a worker should respond to.
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
     * @param int $signal
     */
    public function signalHandler($signal)
    {
        $this->emit($signal);

        // Default signal process
        if ($this->options['signal_handler']) {
            $this->signalHandlerDefault($signal);
        }
    }

    /**
     * Default signal handler
     *
     * @param int $signal
     */
    protected function signalHandlerDefault($signal)
    {
        switch ($signal) {
            case SIGTERM:
            case SIGINT:
                $this->shutdown();
                // Check children
                foreach ($this->children as $child) {
                    $child->kill(SIGINT);
                }
                exit;
                break;
            case SIGQUIT:
                $this->emit('quit');
                break;
            case SIGCHLD:
                while ($pid = pcntl_wait($status, WNOHANG)) {
                    if ($pid === -1) {
                        pcntl_signal_dispatch();
                        break;
                    }

                    $this->children[$pid]->shutdown($status);
                }
                break;
        }
    }

    /**
     * Shutdown
     */
    public function shutdown($status = 0)
    {
        if (!$this->process->isExit()) {
            // Check children
            foreach ($this->children as $child) {
                $child->shutdown($status);
            }
            $this->process->status = $status;
            $this->emit('exit', $status);
        }
    }

    /**
     * Shutdown handlers
     */
    protected function registerShutdownHandlers()
    {
        $that = $this;
        register_shutdown_function(function () use ($that) {
            if (($error = error_get_last()) && in_array($error['type'], array(E_PARSE, E_ERROR, E_USER_ERROR))) {
                $that->shutdown(1);
            } else {
                $that->shutdown();
            }
        });
    }

    /**
     * Register tick handlers
     */
    protected function registerTickHandlers()
    {
        $that = $this;
        register_tick_function(function () use ($that) {
            if (!$that->prepared) {
                return;
            }

            if (!is_resource($that->queue) || !msg_stat_queue($that->queue)) {
                return;
            }

            while (msg_receive($that->queue, 1, $null, 1024, $msg, true, MSG_IPC_NOWAIT, $error)) {
                if (!is_array($msg) || empty($msg['to']) || $msg['to'] != $that->pid) {
                    $that->emit('unknown_message', $msg);
                } else {
                    if ($that->master) {
                        if ($process = $that->children[$msg['from']]) {
                            $process->emit('message', $msg['body']);
                        } else {
                            $that->emit('unknown_message', $msg);
                        }
                    } else if ($msg['from'] == $that->ppid) {
                        // Come from parent process
                        $that->process->emit('message', $msg['body']);
                    }
                }
            }

            $that->emit('tick');
        });

        $this->on('exit', function () use ($that) {
            if (!is_resource($that->queue) || !msg_stat_queue($that->queue)) {
                return;
            }

            msg_remove_queue($that->queue);
        });
    }
}