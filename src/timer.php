<?php

function defer(Closure $closure)
{
    Fiber::suspend();
    $closure();
}

function delay(float $seconds, Closure $closure)
{
    if ($seconds < 0) {
        throw new Exception("Seconds must be positive");
    }
    $deadline = microtime(true) + $seconds;
    $done = false;
    Fiber::suspend();
    while (!$done) {
        if (microtime(true) < $deadline) {
            Fiber::suspend();
        }
        else {
            $done = true;
            $closure();
        }
    }
}

function repeat(float $seconds, Closure $closure)
{
    if ($seconds < 0) {
        throw new Exception("Seconds must be positive");
    }
    $deadline = microtime(true) + $seconds;
    Fiber::suspend();
    $done = false;
    while (!$done) {
        if (microtime(true) < $deadline) {
            Fiber::suspend();
        }
        else {
            $change = $closure($seconds);
            if ($change !== null) {
                $seconds = $change;
            }
            $now = microtime(true);
            $deadline = $now + $seconds;
            $done = ($deadline <= $now);
        }
    }
}


