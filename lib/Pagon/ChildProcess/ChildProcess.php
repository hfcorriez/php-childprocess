<?php

namespace Pagon\ChildProcess;

use Pagon\EventEmitter;

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
     * @var ChildProcess
     */
    protected static $current;

    /**
     * Instance for current process
     */
    public static function current(array $option = array())
    {
        if (!self::$current) {
            self::$current = new self($option);
        }
        return self::$current;
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
        $this->process = new Process($this, $this->pid, $this->ppid, true);
        $this->registerSigHandlers();
        $this->registerShutdownHandlers();
        $this->registerTickHandlers();
    }

    /**
     * Run the closure in parallel space
     *
     * @param callable|Process $closure
     * @param array|\Closure   $options
     * @param bool             $auto_start
     * @throws \RuntimeException
     * @return Process
     */
    public function parallel($closure, $options = array(), $auto_start = true)
    {
        // Check auto_start
        if (is_bool($options)) {
            $auto_start = $options;
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

        // Process init
        if ($options['init'] instanceof \Closure) {
            $options['init']($child);
        }

        if (!$auto_start) {
            $child->register($closure, $options);
            return $child;
        }

        // Fork
        $pid = pcntl_fork();

        // Parallel works
        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork child process.');
        } else if ($pid) {
            $child->emit('fork');
            // Save child process and return
            return $this->children[$pid] = $child->init($pid);
        } else {
            // Child initialize
            $this->childInitialize($options);

            // Support callable
            call_user_func($closure, $child);
            exit;
        }
    }

    /**
     * Attempt to fork a child process from the parent to run a job in.
     *
     * @param string         $file
     * @param array|\Closure $options
     * @param bool           $auto_start
     * @return Process
     */
    public function fork($file, $options = array(), $auto_start = true)
    {
        $process = $this->process;
        return $this->parallel(function () use ($file, $process) {
            if (is_string($file) && is_file($file)) {
                include($file);
            } else {
                throw new \RuntimeException('Bad file');
            }
        }, $options, $auto_start);
    }

    /**
     * Spawn the command
     *
     * @param string         $cmd
     * @param array|\Closure $options
     * @param bool           $auto_start
     * @return Process
     */
    public function spawn($cmd, $options = array(), $auto_start = true)
    {
        // Generate guid
        $guid = uniqid();
        // Get create directory
        $dir = is_array($options) && !empty($options['dir']) ? $options['dir'] : '/tmp';
        // Events name
        $types = array('stdin', 'stdout', 'stderr');
        // Files to descriptor
        $files = array();
        // Self
        $that = $this;

        // Define the stdin stdout and stderr files
        foreach ($types as $type) {
            $files[] = $file = $dir . '/' . $guid . '.' . $type;
            posix_mkfifo($file, 0600);
        }

        $child = $this->parallel(function ($child) use ($cmd, $files) {
            $pipes = array();
            $options = $child->options;

            // Make file descriptors for proc_open()
            $fd = array();
            foreach ($files as $i => $file) {
                $fd[] = array('file', $file, $i > 0 ? 'w' : 'r');
            }

            // Open pipe to run process
            $resource = proc_open($cmd, $fd, $pipes, $options['cwd'], $options['env']);

            if (!is_resource($resource)) {
                throw new \RuntimeException('Can not run "' . $cmd . '" using pipe open');
            }

            // Close all pipes
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }

            proc_close($resource);
            exit;
        }, $options, $auto_start);

        $child->on('fork', function () use (&$files, $child, $types, $that) {
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
            $that->on('tick', $tick);

            // Remove file when exit
            $child->on('exit', function () use ($tick, $that, &$files, &$pipes) {
                $that->removeListener('tick', $tick);
                foreach ($files as $file) {
                    unlink($file);
                }
                $files = $pipes = array();
            });
        });

        return $child;
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
        $this->removeAllListeners();
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
            // Check children
            foreach ($this->children as $child) {
                $child->shutdown($status, $info);
            }
            $this->emit('exit', $status, $info);
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
                $that->shutdown(1, $error);
            } else {
                $that->shutdown();
            }

            if (!is_resource($that->queue) || !msg_stat_queue($that->queue)) {
                return;
            }

            msg_remove_queue($that->queue);
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

            if ($that->master) {
                while ($pid = pcntl_wait($status, WNOHANG)) {
                    if ($pid === -1) {
                        pcntl_signal_dispatch();
                        break;
                    }

                    $that->children[$pid]->shutdown($status);
                    $that->clear($pid);
                }
            }

            $that->emit('tick');

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
        });
    }
}