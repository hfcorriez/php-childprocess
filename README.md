其它语言：[English](README_en.md)

# PHP-ChildProcess

一个CLI下易用简单的管理进程的库

# 安装

添加 `"pagon/childprocess": "*"` 到 [`composer.json`](http://getcomposer.org):

```
composer.phar install
```

# 目录

- [使用管理器](#使用管理器)
- [创建子进程](#创建子进程)
  - [自动运行](#自动运行)
  - [手动运行](#手动运行)
  - [手动运行等待](#手动运行等待)
  - [运行PHP文件](#运行PHP文件)
  - [运行命令](#运行命令)
- [其它](#其它)
  - [发送消息](#发送消息)
  - [高级用法](#高级用法)
- [事件](#事件)
  - [ChildProcess](#childprocess事件)
  - [Process](#process事件)

# 使用

## 使用管理器

控制当前进程

```php
declare(ticks = 1) ;

$manager = new ChildProcess();

$manager->on('exit', function () use ($process) {
    error_log('exit');
    exit;
});

// 做其他事情或等待
```

## 创建子进程

### 简单运行

在子平行空间运行闭包函数

```php
declare(ticks = 1) ;

$manager = new ChildProcess();

$child = $manager->parallel(function () {
    sleep(10);
    // 做其他事情
});
```

> 为了保证主进程不退出来handle事件，可以使用`join`或者`while(1)`来保证运行

### 手动运行

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

// 只运行子进程不等待退出
$child->run()

while(1) { /*to do something */}
```

### 手动运行等待

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

// 等待子进程退出
$child->join();
```

### 运行PHP文件

在子平行空间运行PHP文件

主进程：

```php
declare(ticks = 1) ;

$manager = new ChildProcess();

$child = $manager->fork(__DIR__ . '/worker.php', false);

$child->on('exit', function ($status) {
    error_log('child exit ' . $status);
});

$child->join();
```

PHP文件：

```php
$process // The parent process
// 需要做的工作
```

### 运行命令

在子进程运行命令，并且捕获输出

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

## 其它

### 发送消息

父子进程可以使用消息来通信

```php
declare(ticks = 1) ;

$manager = new ChildProcess();

$manager->listen();

$child = $manager->parallel(function (Process $process) {
    $process->on('message', function ($msg) {
        error_log('child revive message: ' . $msg);
    });

    $process->listen();

    $process->send('hello master');

    error_log('child execute');
}, false);

$child->on('message', function ($msg) {
    error_log('parent receive message: ' . $msg);
});

$child->send('hi child');

$child->join();
```

### 高级用法

#### 设置选项

当前支持的选项列表：

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

一些用法：

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

## 事件

### ChildProcess事件

- `tick`      每个tick都会触发，主要用于监控一些行为来及时反馈到管理器
- `listen`    监听消息队列
- `signal`    收到任何的信号
- `abort`     中断时：SIGINT,SIGTERM
- `finish`    运行结束时（正常退出）
- `exit`      管理器进程退出，包含`abort`和`finish`

### Process事件

- `listen`    当前进程实例开始监听队列时触发
- `run`       当前进程实例手动运行时
- `init`      当前进程实例子进程创建完成时
- `fork`      当前进程实例fork时
- `abort`     当前进程实例中断时：SIGINT,SIGTERM
- `finish`    当前进程实例结束时（正常退出）
- `exit`      当前进程实例退出时，包含`abort`和`finish`

# 授权

[MIT](./LICENSE)