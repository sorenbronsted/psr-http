<?php

require __DIR__ . '/../vendor/autoload.php';

use Revolt\EventLoop;


$store = [];

EventLoop::repeat(5, function () use(&$store) {
    $suspension = EventLoop::getSuspension();
    echo "Alocated " . count($store) . PHP_EOL;
});

EventLoop::repeat(1, function () {
    $memory = memory_get_usage() / 1024;
    $formatted = number_format($memory).' KiB';
    echo "Current memory usage: {$formatted}\n";
});

EventLoop::run();
