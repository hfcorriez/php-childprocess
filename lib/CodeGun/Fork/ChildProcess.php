<?php

namespace CodeGun\Fork;

declare(ticks = 1) ;

class ChildProcess extends Process
{
    /**
     * @var Process
     */
    protected $process;

    /**
     * @var Process[]
     */
    protected static $children;

    /**
     * @var array Default options for child process
     */
    protected static $default_options = array(
        'cwd'     => false,
        'user'    => false,
        'env'     => array(),
        'timeout' => 0
    );

    /**
     * Init
     */
    public function __construct()
    {
        $pid = posix_getpid();
        parent::__construct($pid, $pid);
        $this->registerSigHandlers();
        $this->registerShutdownHandlers();
    }

    /**
     * Attempt to fork a child process from the parent to run a job in.
     *
     * @param callable $call
     * @param array    $options
     * @throws \RuntimeException
     * @return Process
     */
    public function fork($call, $options = array())
    {
        // Merge options
        $options = $this->getOptions($options);

        // Fork
        $pid = pcntl_fork();

        // Parallel works
        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork child process.');
        } else if ($pid) {
            // Save child process and return
            return self::$children[$pid] = new Process($pid, $this->pid);
        } else {
            if (is_callable($call)) {
                // Process options
                $this->childProcessOptions($options);

                // Support callable
                call_user_func_array($call, array($this));
            } else if (is_string($call) && is_file($call)) {
                // Process options
                $this->childProcessOptions($options);

                // Support PHP file
                $process = $this;
                include($call);
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
     * @param array  $options
     * @throws \RuntimeException
     * @return Process
     */
    public function spawn($cmd, array $options = array())
    {
        // Merge options
        $options = $this->getOptions($options);

        $guid = uniqid();

        $files = array(
            "/tmp/$guid.in",
            "/tmp/$guid.out",
            "/tmp/$guid.err",
        );

        // Get can be changed user
        $options['user'] = $user = $options['user'] ? $this->tryChangeUser($options['user']) : false;

        // Make pipes
        foreach ($files as $file) {
            posix_mkfifo($file, 0666);
            // Check if need to change user
            if ($user) {
                // Change owner to changed user
                chown($file, $user['name']);
            }
        }

        // Fork
        $pid = pcntl_fork();

        // Parallel works
        if ($pid === -1) {
            throw new \RuntimeException('Unable to fork child process.');
        } else if ($pid) {
            // Save process
            self::$children[$pid] = $child = new Process($pid, $this->pid);

            // Make file descriptor
            $pipes = array();
            foreach ($files as $i => $file) {
                $pipes[] = fopen($file, $i > 0 ? 'r' : 'w');
            }

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
            $this->childProcessOptions($options);

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
    }

    /**
     * Get options
     *
     * @param array $options
     * @return array
     */
    protected function getOptions($options = array())
    {
        return $options + self::$default_options;
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
        switch ($signal) {
            case SIGTERM:
            case SIGINT:
                $this->status = 0;
                $this->emit('exit');
                exit;
                break;
            case SIGQUIT:
                $this->emit('quit');
                break;
            case SIGCHLD:
                while (($pid = pcntl_wait($status)) > 0) {
                    self::$children[$pid]->status = $status;
                    self::$children[$pid]->emit('exit', $status);
                }
                break;
        }
    }

    /**
     * Shutdown handlers
     */
    protected function registerShutdownHandlers()
    {
        $that = $this;
        register_shutdown_function(function () use ($that) {
            if (!$that->isExit()) {
                if (($error = error_get_last()) && in_array($error['type'], array(E_PARSE, E_ERROR, E_USER_ERROR))) {
                    $that->emit('exit', 1);
                } else {
                    $that->emit('exit', 0);
                }
            }
            $that->emit('shutdown');
        });
    }
}