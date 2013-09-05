# PHP-ChildProcess

    This is a library for PHPer to handle Child Process easy and simple. That's it.

# Dependencies

- php5.3+
- pcntl
- posix
- [pagon/eventemitter](https://github.com/hfcorriez/php-eventemitter)

# Install

add `"pagon/childprocess": "*"` to you `composer.json`:

```
composer.phar install
```

# Overview

- [Use ChildProcess](#childprocess-manager)
- [Create Child Process](#create-child-process)
  - [Parallel works with closure](#parallel-works)
    - [Automatic Run](#automatic-run)
    - [Manually Run](#manually-run)
    - [Manually Join](#manually-join)
  - [Fork PHP file](#fork-php-file)
  - [Send message](#send-message)
  - [Spawn the command](#spawn-the-command)
  - [Advance usage](#advance-usage)
- [Events](#events)
  - [ChildProcess](#manager-events)
  - [Process](#process-events)

# Usage

## ChildProcess Manager

    Current process handle

```php
declare(ticks = 1) ;

$manager = new ChildProcess();

$manager->on('exit', function () use ($master) {
    error_log('exit');
    exit;
});

// To do something
```

## Create Child Process

### Parallel Works

    Run the callable function in parallel child process space

#### Automatic run

```php
declare(ticks = 1) ;

$manager = new ChildProcess();

$child = $manager->parallel(function () {
    sleep(10);
    // to do something
});
```

#### Manually Run

```php
declare(ticks = 1) ;

$manager = new ChildProcess();

$child = $manager->parallel(function () {
    // to do something
    sleep(10);
    error_log('child execute');
}, false);

$child->on('exit', function ($status) {
    error_log('child exit ' . $status);
});

// Will run but don't wait the child exit
$child->run()

while(1) { /*to do something */}
```

#### Manually Join


```php
declare(ticks = 1) ;

$manager = new ChildProcess();

$child = $manager->parallel(function () {
    // to do something
    sleep(10);
    error_log('child execute');
}, false);

$child->on('exit', function ($status) {
    error_log('child exit ' . $status);
});

// Will wait the child exit
$child->join();
```

### Fork PHP file

    Run the PHP file in parallel child process space

The Master:

```php
declare(ticks = 1) ;

$manager = new ChildProcess();

$child = $manager->fork(__DIR__ . '/worker.php', false);

$child->on('exit', function ($status) {
    error_log('child exit ' . $status);
});

$child->join();
```

The Fork PHP file:

```php
$master // The parent process
$child  // Current process
// Some thing to do in child process
```

### Send message

    Message communicate between parent process and child process

```php
declare(ticks = 1) ;

$manager = new ChildProcess();

$manager->listen();

$child = $manager->parallel(function (Process $master, Process $child) {
    $child->listen();

    $master->on('message', function ($msg) {
        error_log('child revive message: ' . $msg);
    });

    $master->send('hello master');

    error_log('child execute');
}, false);

$child->on('message', function ($msg) {
    error_log('parent receive message: ' . $msg);
});

$child->send('hi child');

$child->join();
```

### Spawn the command

    Run the command in child process

```php
declare(ticks = 1) ;

$manager = new ChildProcess();

$child = $manager->spawn('/usr/sbin/netstat');

$child->on('stdout', function ($data) {
    error_log('receive stdout data: '  . $data);
    // to save data or process it
});

$child->on('stderr', function ($data) {
    error_log('receive stderr data: '  . $data);
    // to save data or process something
});

$child->join();
```

### Advance usage

    Setting options

Current setting supported:

```php
array(
    'cwd'      => false,        // Current working deirectory
    'user'     => false,        // Startup user
    'env'      => array(),      // Enviroments
    'timeout'  => 0,            // Timeout
    'init'     => false,        // Init callback
    'callback' => false         // Child startup callback
)
```

Some usage:

```php
declare(ticks = 1) ;

$manager = new ChildProcess();

$child = $manager->spawn('/usr/sbin/netstat', array(
    'timeout' => 60 // Will wait 60 seconds
    'callback' => function(){ error_log('netstat start'); }
));

$child->on('stdout', function ($data) {
    echo $data;
    // to save data or process it
});

$child->join();
```

## Events

### Register Events

```
$manager = new ChildProcess();

$manager->on('tick', function(){
    // Check something
});
```

### Manager Events

- `tick`      Every tick will trigger this
- `listen`    Listen the message
- `exit`      When process is exit
- `quit`      When SIGQUIT received
- `signal`    When signal received, All

### Process Events

- `listen`    When manager listen the message queue, run in master
- `exit`      When exit, run in master
- `run`       When process run in child, run in master
- `init`      When child process, run in master

# License

[MIT](./LICENSE)