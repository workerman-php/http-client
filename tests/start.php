<?php

use Workerman\Events\Swow;
use Workerman\Events\Swoole;
use Workerman\Events\Fiber;
use Workerman\Protocols\Http\Chunk;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
use Workerman\Worker;

require_once __DIR__ . '/../vendor/autoload.php';


if (class_exists(Revolt\EventLoop::class) && (DIRECTORY_SEPARATOR === '/' || !extension_loaded('swow'))) {
    create_test_worker(function () {
        (new PHPUnit\TextUI\Application)->run([
            __DIR__ . '/../vendor/bin/phpunit',
            '--colors=always',
            ...glob(__DIR__ . '/*Test.php')
        ]);
    }, Fiber::class);
}


if (extension_loaded('Swoole')) {
    create_test_worker(function () {
        (new PHPUnit\TextUI\Application)->run([
            __DIR__ . '/../vendor/bin/phpunit',
            '--colors=always',
            ...glob(__DIR__ . '/*Test.php')
        ]);
    }, Swoole::class);
}

if (extension_loaded('Swow')) {
    create_test_worker(function () {
        (new PHPUnit\TextUI\Application)->run([
            __DIR__ . '/../vendor/bin/phpunit',
            '--colors=always',
            ...glob(__DIR__ . '/*Test.php')
        ]);
    }, Swow::class);
}

function create_test_worker(Closure $callable, $eventLoopClass): void
{
    $worker = new Worker();
    $worker->eventLoop = $eventLoopClass;
    $worker->onWorkerStart = function () use ($callable, $eventLoopClass) {
        $fp = fopen(__FILE__, 'r+');
        flock($fp, LOCK_EX);
        echo PHP_EOL . PHP_EOL. PHP_EOL . '[TEST EVENT-LOOP: ' . basename(str_replace('\\', '/', $eventLoopClass)) . ']' . PHP_EOL;
        try {
            $callable();
        } catch (Throwable $e) {
            echo $e;
        } finally {
            flock($fp, LOCK_UN);
        }
        Timer::repeat(1, function () use ($fp) {
            if (flock($fp, LOCK_EX | LOCK_NB)) {
                if(function_exists('posix_kill')) {
                    posix_kill(posix_getppid(), SIGINT);
                } else {
                    Worker::stopAll();
                }
            }
        });
    };
}

$http = new Worker('http://127.0.0.1:7171');

$http->onMessage = function ($connection, Request $request) {
    $path = $request->path();
    switch ($path) {
        case '/get':
            $connection->send(json_encode($request->get()));
            return;
        case '/head':
            $body = json_encode(['ok' => true]);
            if (strtoupper($request->method()) === 'HEAD') {
                $length = strlen($body);
                $connection->send("HTTP/1.1 200 OK\r\nServer: workerman\r\nContent-Length: $length\r\nConnection: keep-alive\r\n\r\n", true);
                return;
            }
            $connection->send($body);
            return;
        case '/post':
            $connection->send(json_encode($request->post()));
            return;
        case '/upload':
            $connection->send(md5_file($request->file('file')['tmp_name']) . ' ' . json_encode($request->post()));
            return;
        case '/stream':
            $id = Timer::repeat(0.1, function () use ($connection, &$id) {
                static $i = 0;
                if ($i === 0) {
                    // 发送thunk 头
                    $connection->send(new Response(200, ['Transfer-Encoding' => 'chunked']));
                }
                $connection->send(new Chunk($i++));
                if ($i > 10) {
                    Timer::del($id);
                    $connection->send(new Chunk(''));
                }
            });
            return;
        case '/exception':
            $connection->close();
            return;
        default:
            $connection->send('Hello World');
    }
};

Worker::runAll();
