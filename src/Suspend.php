<?php

namespace bronsted;

use Fiber;

class Suspend
{
    private bool $state;

    public function __construct()
    {
        $this->state = true;
    }

    public function done()
    {
        $this->state = false;
    }

    public function wait()
    {
        while($this->state) {
            Fiber::suspend();
        }
    }
}