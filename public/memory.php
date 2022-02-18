<?php

require __DIR__ . '/../vendor/autoload.php';

use Revolt\EventLoop;


EventLoop::repeat(1, function () {
    $memory = memory_get_usage() / 1024;
    $formatted = number_format($memory).' KiB';
    echo "Current memory usage: {$formatted}\n";
});

EventLoop::run();
