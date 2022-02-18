<?php

function readStream(mixed $resource, Closure $closure)
{
    $done = false;
    while(!$done) {
        Fiber::suspend();
        $read = [$resource];
        $write = null;
        $expect = null;
        $n = stream_select($read, $write, $expect, 0, 0);
        if ($n > 0) {
            $closure($resource);
        }
        $done = feof($resource) || !is_resource($resource);
    }
}

function writeStream(mixed $resource, Closure $closure)
{
    $done = false;
    while(!$done) {
        Fiber::suspend();
        $read = null;
        $write = [$resource];
        $expect = null;
        $n = stream_select($read, $write, $expect, 0, 0);
        if ($n > 0) {
            $closure($resource);
        }
        $done = feof($resource) || !is_resource($resource);
    }
}

