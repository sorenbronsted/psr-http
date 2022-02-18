<?php

use Revolt\EventLoop;

require __DIR__ . '/../vendor/autoload.php';


function readHttpLine($stream): string
{
    $stop = false;
    $chars = '';
    $suspension = EventLoop::getSuspension();
    $watcher = EventLoop::onReadable($stream, fn () => $suspension->resume());
    while(!feof($stream) && !$stop) {
        do {
            $byte = fread($stream, 1);
            $chars .= $byte;
        } while($byte && $byte != "\n");
        $stop = (strlen($chars) > 1 && $chars[-1] == "\n");
        if (!$stop) {
            $suspension->suspend();
        }
    }
    EventLoop::cancel($watcher);
    return $chars;
}

function readHeaders($stream)
{
    $stop = false;
    while(!$stop) {
        $line = readHttpLine($stream);
        echo trim($line) . PHP_EOL;
        // end-of-headers is one line with \r\n
        $stop = (strlen($line) == 2 && $line[0] == "\r" && $line[1] == "\n");
    }
}

function run()
{
    // start TCP/IP server on localhost:8080
    // for illustration purposes only, should use socket abstracting instead
    $server = \stream_socket_server('tcp://0.0.0.0:8000');
    if (!$server) {
        exit(1);
    }
    \stream_set_blocking($server, false);

    echo "Visit http://localhost:8080/ in your browser." . PHP_EOL;

    // wait for incoming connections on server socket
    EventLoop::onReadable($server, function ($watcher, $server) {
        $conn = \stream_socket_accept($server);
        \stream_set_blocking($conn, false);
        readHeaders($conn);
        $data = "HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: 3\r\n\r\nHi\n";
        EventLoop::onWritable($conn, function ($watcher, $conn) use (&$data) {
            $written = \fwrite($conn, $data);
            if ($written === \strlen($data)) {
                \fclose($conn);
                EventLoop::cancel($watcher);
            } else {
                $data = \substr($data, $written);
            }
        });
    });

    EventLoop::repeat(2, function () {
        $memory = \memory_get_usage() / 1024;
        $formatted = \number_format($memory) . ' KiB';
        echo "Current memory usage: {$formatted}\n";
    });

    EventLoop::run();
}

run();