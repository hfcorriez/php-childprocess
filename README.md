## What's PHP-ChildProcess

    This is a library for PHPer to handle Child Process easy and simple. That's it.

## Dependency

- php5.3+
- pcntl
- posix

## Examples

### Current Process

```php
declare(ticks = 1) ;

$process = new ChildProcess();

$process->on(SIGINT, function () use ($process) {
    error_log('shutdown');
    exit;
});

while(1) {
    // to do something
}
```

### Parallel Work

```php
declare(ticks = 1) ;

$process = new ChildProcess();

$child = $process->parallel(function () {
    // to do something
    sleep(10);
    error_log('child execute');
});

$child->on('exit', function ($status) {
    error_log('child exit ' . $status);
});

while(1) {
    // to do something
}
```

### Send message to child or parent

```php
declare(ticks = 1) ;

$process = new ChildProcess();

$child = $process->parallel(function (Process $process) {
    $process->on('message', function ($msg) {
        error_log('child revive message: ' . $msg);
    });

    $process->send('hello master');

    error_log('child execute');
});

$child->on('message', function ($msg) {
    error_log('parent receive message: ' . $msg);
});

$child->send('hi child');

while (1) {
    // to do some thing
}
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