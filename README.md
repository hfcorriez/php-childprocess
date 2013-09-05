## What's PHP-ChildProcess

    This is a library for PHPer to handle Child Process easy and simple. That's it.

## Dependencies

- php5.3+
- pcntl
- posix
- [pagon/eventemitter](https://github.com/hfcorriez/php-eventemitter)

## Examples

### Use Manager

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

### Parallel Works

    Run the callable function in parallel child process space

```php
declare(ticks = 1) ;

$process = new ChildProcess();

$child = $process->parallel(function () {
    sleep(10);
    // to do something
});
```

Or start manually

```php
declare(ticks = 1) ;

$process = new ChildProcess();

$child = $process->parallel(function () {
    // to do something
    sleep(10);
    error_log('child execute');
}, false);

$child->on('exit', function ($status) {
    error_log('child exit ' . $status);
});

// Will wait the child exit
$child->join();

// Will run but don't wait the child exit
$child->run
```

### Fork with the PHP file

    Run the PHP file in parallel child process space

The Master:

```php
declare(ticks = 1) ;

$process = new ChildProcess();

$child = $process->fork(__DIR__ . '/worker.php', false);

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

$child = $process->parallel(function (Process $master, Process $child) {
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

### Api document

	wait for release...

## License 

(The MIT License)

Copyright (c) 2012 hfcorriez &lt;hfcorriez@gmail.com&gt;

Permission is hereby granted, free of charge, to any person obtaining
a copy of this software and associated documentation files (the
'Software'), to deal in the Software without restriction, including
without limitation the rights to use, copy, modify, merge, publish,
distribute, sublicense, and/or sell copies of the Software, and to
permit persons to whom the Software is furnished to do so, subject to
the following conditions:

The above copyright notice and this permission notice shall be
included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED 'AS IS', WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE
SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.