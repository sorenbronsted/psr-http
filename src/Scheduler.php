<?php

namespace bronsted;

use Closure;
use Fiber;
use SplQueue;
use function count;

class Scheduler
{
    private SplQueue $queue;

    public function __construct()
    {
        $this->queue = new SplQueue();
    }

    public function defer(Closure $closure)
    {
        $this->enqueue(defer(...), $closure);
    }

    public function delay(float $seconds, Closure $closure)
    {
        $this->enqueue(delay(...), $seconds, $closure);
    }

    public function repeat(float $interval, Closure $closure)
    {
        $this->enqueue(repeat(...), $interval, $closure);
    }

    public function onReadable(mixed $resource, Closure $closure)
    {
        $this->enqueue(readStream(...), $resource, $closure);
    }

    public function onWriteable(mixed $resource, Closure $closure)
    {
        $this->enqueue(writeStream(...), $resource, $closure);
    }

    public function onSignal(int $id, Closure $closure)
    {
        $this->enqueue(signal(...), $id, $closure);
    }

    public function run()
    {
        $n = count($this->queue);
        while ($n > 0) {
            $fiber = $this->queue->dequeue();
            if ($fiber->isSuspended()) {
                $fiber->resume();
            }
            if (!$fiber->isTerminated()) {
                $this->queue->enqueue($fiber);
            }
            $n = count($this->queue);
        }
    }

    private function enqueue(Closure $closure, ...$args)
    {
        $fiber = new Fiber($closure);
        $this->queue->enqueue($fiber);
        $fiber->start(...$args);
    }
}
