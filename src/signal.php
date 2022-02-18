<?php

function signal(int $id, Closure $closure)
{
    $done = false;
    pcntl_signal($id,  function() use($closure, &$done) {
        $closure();
        $done = true;
    });

    while(!$done) {
        Fiber::suspend();
        pcntl_signal_dispatch();
    }
}
