<?php

namespace bronsted;

use Closure;
use Fiber;
use SplQueue;
use function array_merge;
use function count;
use function readStream;
use function writeStream;
use function readStreamReady;
use function writeStreamReady;

class Scheduler
{
    /**
     * @var SplQueue the holding current fibers
     */
    private SplQueue $queue;

    /**
     * @var Scheduler|null singleton if used
     */
    private static ?Scheduler $instance = null;

    public function __construct()
    {
        $this->queue  = new SplQueue();
    }

    public static function instance(): Scheduler
    {
        if (self::$instance == null) {
            self::$instance = new Scheduler();
        }
        return self::$instance;
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

    public function onReadableReady(mixed $resource, Closure $closure)
    {
        $this->enqueue(readStreamReady(...), $resource, $closure);
    }

    public function onWriteable(mixed $resource, Closure $closure)
    {
        $this->enqueue(writeStream(...), $resource, $closure);
    }

    public function onWriteableReady(mixed $resource, Closure $closure)
    {
        $this->enqueue(writeStreamReady(...), $resource, $closure);
    }

    public function onSignal(int $id, Closure $closure)
    {
        $this->enqueue(signal(...), $id, $closure);
    }

    public function run()
    {
        while(!$this->queue->isEmpty()) {
            $fiber = $this->queue->dequeue();
            if ($fiber->isSuspended()) {
                $fiber->resume();
            }
            if (!$fiber->isTerminated()) {
                $this->queue->enqueue($fiber);
            }
        }
    }

    private function enqueue(Closure $closure, ...$args)
    {
        $fiber = new Fiber($closure);
        $this->queue->enqueue($fiber);
        $fiber->start(...$args);
    }
}
