<?php

namespace bronsted;

use Closure;
use Fiber;
use SplDoublyLinkedList;
use SplQueue;
use function array_merge;
use function count;
use function microtime;
use function usleep;

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

    /**
     * @var array fibers ready to be scheduled
     */
    private array $ready;

    public function __construct()
    {
        $this->ready  = [];
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
        // the current queue to run
        $current = $this->ready;
        // ready is moved to current, so we can reset it for new fibers
        $this->ready = [];
        // Do we have anything to run
        $n = count($current);
        // Continue to run until no fibers a left
        while($n > 0) {
            $next = [];
            // run the current queue
            for($i = 0; $i < $n; $i++) {
                $fiber = $current[$i];
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                }
                if ($fiber->isTerminated()) {
                    continue;
                }
                // fiber is not finished so it is scheduled next run
                $next[] = $fiber;
            }
            // by now thr current queue hold terminated fibers, which is ready for gc
            // form the new queue
            $current = array_merge($next, $this->ready);
            // new fibers are scheduled
            $this->ready = [];
            // do we any any fibers
            $n = count($current);
        }
    }

    private function enqueue(Closure $closure, ...$args)
    {
        $fiber = new Fiber($closure);
        $this->ready[] = $fiber;
        $fiber->start(...$args);
    }
}
