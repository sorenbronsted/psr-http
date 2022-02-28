<?php

function readStream(mixed $stream, Closure $closure)
{
    while(true) {
        Fiber::suspend();
        $done = !is_resource($stream) || feof($stream);
        if ($done) {
            return;
        }
        $read = [$stream];
        $write = null;
        $expect = null;
        $n = stream_select($read, $write, $expect, 0, 0);
        if ($n > 0) {
            $closure($stream);
        }
    }
}

function writeStream(mixed $stream, Closure $closure)
{
    while(true) {
        Fiber::suspend();
        $done = !is_resource($stream) || feof($stream);
        if ($done) {
            return;
        }
        $read = null;
        $write = [$stream];
        $expect = null;
        $n = stream_select($read, $write, $expect, 0, 0);
        if ($n > 0) {
            $closure($stream);
        }
    }
}

