<?php

namespace bronsted;

use Exception;
use Fiber;
use function microtime;

class Suspend
{
    private bool $state;
    private int $deadline;

    public function __construct(float $timeout = 5)
    {
        $this->state = true;
        $this->deadline = microtime(true) + $timeout;
    }

    public function done()
    {
        $this->state = false;
    }

    public function wait()
    {
        while($this->state) {
            if (microtime(true) > $this->deadline) {
                throw new Exception('Timeout waiting');
            }
            Fiber::suspend();
        }
    }
}