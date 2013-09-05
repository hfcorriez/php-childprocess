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
  - [Automatic Run](#automatic-run)
  - [Manually Run](#manually-run)
  - [Manually Join](#manually-join)
  - [Fork PHP file](#fork-php-file)
  - [Spawn the command](#spawn-the-command)
- [Other](#other)
  - [Send message](#send-message)
  - [Advance usage](#advance-usage)
- [Events](#events)
  - [ChildProcess](#childprocess-events)
  - [Process](#process-events)

# Usage

## ChildProcess Manager

Current process handle

```php
declare(ticks = 1) ;

$manager = new ChildProcess();

$manager->on('exit', function () use ($process) {
    error_log('exit');
    exit;
});

// To do something
```

## Create Child Process

Run the closure function in parallel child process space

#### Automatic run

```php
declare(ticks = 1) ;

$manager = new ChildProcess();

$child = $manager->parallel(function () {
    sleep(10);
    // to do something
});
```

> To keep master running to handle the events, you can use `join` or use `while(1)` for forever run

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
$process // The parent process
// Some thing to do in child process
```

### Spawn the command

Run the command in child process

```php
declare(ticks = 1) ;

$manager = new ChildProcess();

$child = $manager->spawn('/usr/sbin/netstat', false);

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

## Other

### Send message

Message communicate between parent process and child process

```php
declare(ticks = 1) ;

$manager = new ChildProcess();

$manager->listen();

$child = $manager->parallel(function (Process $process) {
    $process->on('message', function ($msg) {
        error_log('child revive message: ' . $msg);
    });

    $child->listen();

    $process->send('hello master');

    error_log('child execute');
}, false);

$child->on('message', function ($msg) {
    error_log('parent receive message: ' . $msg);
});

$child->send('hi child');

$child->join();
```

### Advance usage

#### Setting options

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
), false);

$child->on('stdout', function ($data) {
    echo $data;
    // to save data or process it
});

$child->join();
```

## Events

### Manager Events

- `tick`      Every tick will trigger this
- `listen`    Listen the message
- `signal`    When signal received, All
- `abort`     Abort：SIGINT,SIGTERM
- `finish`    Finish process (Normal exit)
- `exit`      When process is exit, includes `abort` and `finish` events

### Process Events

- `listen`    Process instance's manager listen the message queue
- `run`       Process instance run in child
- `init`      Process instance child process created
- `fork`      Process instance fork
- `abort`     Process instance abort：SIGINT,SIGTERM
- `finish`    Process instance finish process (Normal exit)
- `exit`      Process instance exit, includes `abort` and `finish` events

# License

[MIT](./LICENSE)