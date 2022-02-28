<?php

function readStream(mixed $stream, Closure $closure)
{
    while(true) {
        Fiber::suspend();
        if (!isStreamValid($stream)) {
            return;
        }
        $n = selectStream(read: $stream);
        if ($n > 0) {
            $closure($stream);
        }
    }
}

function readStreamReady(mixed $stream, Closure $closure)
{
    while(true) {
        Fiber::suspend();
        if (!isStreamValid($stream)) {
            return;
        }
        $n = selectStream(read: $stream);
        if ($n > 0) {
            $closure($stream);
            return;
        }
    }
}

function writeStream(mixed $stream, Closure $closure)
{
    while(true) {
        Fiber::suspend();
        if (!isStreamValid($stream)) {
            return;
        }
        $n = selectStream(write: $stream);
        if ($n > 0) {
            $closure($stream);
        }
    }
}

function writeStreamReady(mixed $stream, Closure $closure)
{
    while(true) {
        Fiber::suspend();
        if (!isStreamValid($stream)) {
            return;
        }
        $n = selectStream(write: $stream);
        if ($n > 0) {
            $closure($stream);
            return;
        }
    }
}

function isStreamValid(mixed $stream): bool
{
    return is_resource($stream) && !feof($stream);
}

function selectStream($read = null, $write = null): int
{
    $read = $read ? [$read] : null;
    $write = $write ? [$write] : null;
    $expect = null;
    return stream_select($read, $write, $expect, 0, 0);
}