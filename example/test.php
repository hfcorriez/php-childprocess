<?php

require(dirname(__DIR__) . '/vendor/autoload.php');

use Pagon\ChildProcess;
use Pagon\Process;

declare(ticks = 1);

$process = new ChildProcess();

$process->on('exit', function () use ($process) {
    write_log('exit');
    exit;
});

$child = $process->parallel(function (Process $process) {
    $process->on('message', function ($msg) {
        write_log('child revive message: ' . $msg);
    });

    $process->manager->listen();

    write_log('parent: ' . $process->ppid);

    $process->send('hello master');

    for ($i = 0; $i < 30000; $i++) {
        usleep(100);
    }
    write_log('child execute');
}, array('timeout' => 2, 'init' => function (Process $child) {
    $child->on('message', function ($msg) {
        write_log('parent receive message: ' . $msg);
    });
}));

$process->listen();

$child->on('exit', function ($status) {
    write_log('child exit ' . $status);
});

$child->on('listen', function () use ($child) {
    $child->send('hi child');
});

$i = 0;
while (1) {
    echo 'start ' . $i . PHP_EOL;
    if ($child->send('hello ' . $i)) {
        echo 'ok ' . $i . PHP_EOL;
    } else {
        echo 'error ' . $i . PHP_EOL;
    }
    $i++;
    usleep(500000);
}

function write_log($message)
{
    file_put_contents('fork.log', posix_getpid() . ': ' . $message . PHP_EOL, FILE_APPEND);
}