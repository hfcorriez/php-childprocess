<?php

namespace Pagon;

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
        'catch_signal' => true
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
     * @var Process[]
     */
    public $prepared_children = array();

    /**
     * @var array Default options for child process
     */
    protected static $child_options = array(
        'cwd'      => false,
        'user'     => false,
        'env'      => array(),
        'timeout'  => 0,
        'init'     => false,
        'callback' => false
    );

    /**
     * @var ChildProcess
     */
    protected static $self;

    /**
     * Instance for current process
     */
    public static function self(array $option = array())
    {
        if (!self::$self) {
            self::$self = new self($option);
        }
        return self::$self;
    }

    /**
     * Init
     */
    public function __construct(array $options = array())
    {
        // Save options
        $this->options = $options + $this->options;

        // Prepare resource and data
        $this->ppid = $this->pid = posix_getpid();
        $this->process = new Process($this, $this->pid, $this->ppid);
        $this->registerSigHandlers();
        $this->registerShutdownHandlers();
        $this->registerTickHandlers();
    }

    /**
     * Run the closure in parallel space
     *
     * @param callable|Process $closure
     * @param array|\Closure   $options
     * @param bool             $start
     * @throws \RuntimeException
     * @return Process
     */
    public function parallel($closure, $options = array(), $start = true)
    {
        // Check auto_start
        if (is_bool($options)) {
            $start = $options;
            $options = array();
        }

        // Check if process set
        if ($closure instanceof Process) {
            $child = $closure;
            $closure = $child->runner;
            $options = $child->options;
        } else {
            // Build new child
            $child = new Process($this, null, $this->pid);

            // Get options
            $options = $this->getOptions($options);
        }

        // Auto start
        if (!$start) {
            $child->register($closure, $options);
            if (!in_array($child, $this->prepared_children)) {
                $this->prepared_children[] = $child;
            }
            return $child;
        }

        // Process init
        if ($options['init'] instanceof \Closure) {
            $options['init']($child);
        }

        // Fork
        $pid = pcntl_fork();

        // Save child process and return
        $this->children[$pid] = $child;
        // Remove from prepared children
        if (($index = array_search($child, $this->prepared_children)) !== false) {
            unset($this->prepared_children[$index]);
        }

        // Parallel works
        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork child process.');
        } else if ($pid) {
            // Save child process and return
            $child->init($pid);
            $child->emit('fork');
            return $child;
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
     * @param string         $file
     * @param array|\Closure $options
     * @param bool           $start
     * @return Process
     */
    public function fork($file, $options = array(), $start = true)
    {
        return $this->parallel(function ($process) use ($file) {
            if (is_string($file) && is_file($file)) {
                include($file);
            } else {
                throw new \RuntimeException('Bad file');
            }
        }, $options, $start);
    }

    /**
     * Spawn the command
     *
     * @param string         $cmd
     * @param array|\Closure $options
     * @param bool           $start
     * @return Process
     */
    public function spawn($cmd, $options = array(), $start = true)
    {
        // Generate guid
        $guid = uniqid();
        // Get create directory
        $dir = !empty($options['dir']) ? $options['dir'] : sys_get_temp_dir();
        // Events name
        $types = array('stdin', 'stdout', 'stderr');
        // Files to descriptor
        $files = array();
        // Self
        $self = $this;

        // Define the stdin stdout and stderr files
        foreach ($types as $type) {
            $files[] = $file = $dir . '/' . $guid . '.' . $type;
            posix_mkfifo($file, 0600);
        }

        $child = $this->parallel(function ($process) use ($cmd, $files) {
            $pipes = array();

            // Make file descriptors for proc_open()
            $fd = array();
            foreach ($files as $i => $file) {
                $fd[] = array('file', $file, $i > 0 ? 'w' : 'r');
            }

            // Open pipe to run process
            $resource = proc_open($cmd, $fd, $pipes);

            if (!is_resource($resource)) {
                throw new \RuntimeException('Can not run "' . $cmd . '" using pipe open');
            }

            // Close all pipes
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }

            proc_close($resource);
            exit;
        }, $options, $start);

        $child->on('fork', function () use (&$files, $child, $types, $self) {
            // Make file descriptor
            $pipes = array();
            foreach ($files as $i => $file) {
                $pipes[] = fopen($file, $i > 0 ? 'r' : 'w');
            }

            // Create tick process callback
            $tick = function () use ($pipes, $child, $types) {
                $readers = $pipes;

                // Select the streams
                if (empty($readers) || stream_select($readers, $null, $null, 0) <= 0) return;

                // Check which reader selected and emit event
                foreach ($readers as $reader) {
                    if (!is_resource($reader)) continue;
                    $index = array_search($reader, $pipes);
                    while (!feof($reader)) {
                        $child->emit($types[$index], fgets($reader));
                    }
                }
            };

            // Register tick function to check streams
            $self->on('tick', $tick);

            // Remove file when exit
            $child->on('exit', function () use ($tick, $self, &$files, &$pipes) {
                $self->removeListener('tick', $tick);
                foreach ($files as $file) {
                    unlink($file);
                }
                $files = $pipes = array();
            });
        });

        return $child;
    }

    /**
     * Join
     */
    public function wait()
    {
        foreach ($this->prepared_children as $child) {
            $child->run();
        }

        while ($this->children) {
            usleep(100000);
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
        $this->process = new Process($this, $this->pid, $this->ppid);
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
     * Is listened?
     *
     * @return bool
     */
    public function isListened()
    {
        return !!$this->queue;
    }

    /**
     * Register message listener
     */
    public function listen()
    {
        if (!$this->queue) {
            $this->queue = msg_get_queue($this->pid);
            $this->emit('listen');
        }
        return $this;
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
            if (($index = array_search($process, $this->children)) !== false) {
                $this->children[$index]->__destruct();
                unset($this->children[$index]);
            }
        } elseif (is_numeric($process)) {
            if (isset($this->children[$process])) {
                $this->children[$process]->__destruct();
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
        $this->removeAllListeners();
        $this->master = false;
        $pid = posix_getpid();
        $this->ppid = $this->pid;
        $this->pid = $pid;
        $this->queue = null;
        $this->process = new Process($this, $this->pid, $this->ppid);
        $this->children = array();
        $this->prepared = true;

        $options = $options + self::$child_options;

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
            chdir($options['cwd']);
        }

        // User to be change
        if ($options['user']) {
            $this->childChangeUser($options['user']);
        }

        // Env set
        if ($options['env']) {
            $this->childChangeEnv($options['env']);
        }

        // Env set
        if ($options['timeout']) {
            $this->childSetTimeout($options['timeout']);
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
            throw new \RuntimeException('Only root can change user to spawn the process');
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
    protected function childSetTimeout($timeout)
    {
        $start_time = time();
        $self = $this;
        $this->on('tick', function () use ($timeout, $start_time, $self) {
            if ($start_time + $timeout < time()) $self->shutdown(1);
        });
    }

    /**
     * Process change env
     *
     * @param array $env
     */
    protected function childChangeEnv(array $env)
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
    protected function childChangeUser($user)
    {
        if (is_array($user) || ($user = $this->tryChangeUser($user))) {
            posix_setgid($user['gid']);
            posix_setuid($user['uid']);
        }
    }

    /**
     * Get options
     *
     * @param array|\Closure $options
     * @return array
     */
    protected function getOptions($options = array())
    {
        if ($options instanceof \Closure) $options = array('init' => $options);

        return $options + self::$child_options;
    }

    /**
     * Register signal handlers that a worker should respond to.
     */
    protected function registerSigHandlers()
    {
        pcntl_signal(SIGTERM, array($this, 'signalHandler'));
        pcntl_signal(SIGINT, array($this, 'signalHandler'));;
        pcntl_signal(SIGUSR1, array($this, 'signalHandler'));
        pcntl_signal(SIGUSR2, array($this, 'signalHandler'));
        //pcntl_signal(SIGQUIT, array($this, 'signalHandler'));
        //pcntl_signal(SIGCONT, array($this, 'signalHandler'));
        //pcntl_signal(SIGPIPE, array($this, 'signalHandler'));
        //pcntl_signal(SIGCHLD, array($this, 'signalHandler'));
    }

    /**
     * Control signals
     *
     * @param int $signal
     */
    public function signalHandler($signal)
    {
        $this->emit('signal', $signal);
        $this->emit($signal);

        // Default signal process
        if ($this->options['catch_signal']) {
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
                // Check children
                while ($this->children) {
                    foreach ($this->children as $child) {
                        $ok = $child->kill(SIGINT);
                        /* EPERM */
                        if (posix_get_last_error() == 1) $ok = false;

                        if ($ok) {
                            // Emit exit
                            $child->emit('abort', $signal);
                            $child->shutdown($signal);
                            $this->clear($child);
                        }
                    }
                }
                $this->emit('abort', $signal);
                $this->shutdown($signal);
                exit;
                break;
        }
    }

    /**
     * Shutdown with status
     *
     * @param int   $status
     * @param mixed $info
     */
    public function shutdown($status = 0, $info = null)
    {
        if (!$this->process->isExit()) {
            $this->process->status = $status;
            $this->emit('exit', $status, $info);
            $this->process->emit('exit', $status, $info);
        }
    }

    /**
     * Shutdown handlers
     */
    protected function registerShutdownHandlers()
    {
        $self = $this;
        register_shutdown_function(function () use ($self) {
            if (($error = error_get_last()) && in_array($error['type'], array(E_PARSE, E_ERROR, E_USER_ERROR))) {
                $self->shutdown(1, $error);
            } else {
                $self->emit('finish', 0);
                $self->shutdown();
            }

            if (!is_resource($self->queue) || !msg_stat_queue($self->queue)) {
                return;
            }

            msg_remove_queue($self->queue);
        });
    }

    /**
     * Register tick handlers
     */
    protected function registerTickHandlers()
    {
        $self = $this;
        register_tick_function(function () use ($self) {
            if (!$self->prepared) {
                return;
            }

            if ($self->master) {
                while ($pid = pcntl_wait($status, WNOHANG)) {
                    if ($pid === -1) {
                        pcntl_signal_dispatch();
                        break;
                    }

                    if (empty($self->children[$pid])) continue;

                    $self->children[$pid]->emit('finish', $status);
                    $self->children[$pid]->shutdown($status);
                    $self->clear($pid);
                }
            }

            $self->emit('tick');

            if (!is_resource($self->queue) || !msg_stat_queue($self->queue)) {
                return;
            }

            while (msg_receive($self->queue, 1, $null, 1024, $msg, true, MSG_IPC_NOWAIT, $error)) {
                if (!is_array($msg) || empty($msg['to']) || $msg['to'] != $self->pid) {
                    $self->emit('unknown_message', $msg);
                } else {
                    if ($self->master) {
                        if (!empty($self->children[$msg['from']])
                            && ($process = $self->children[$msg['from']])
                        ) {
                            $process->emit('message', $msg['body']);
                        } else {
                            $self->emit('unknown_message', $msg);
                        }
                    } else if ($msg['from'] == $self->ppid) {
                        // Come from parent process
                        $self->process->emit('message', $msg['body']);
                    }
                }
            }
        });
    }
}