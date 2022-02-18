<?php

use bronsted\Defer;
use bronsted\Scheduler;
use PHPUnit\Framework\TestCase;

class DelayTest extends TestCase
{
    public function testOk()
    {
        $called = false;
        $scheduler = new Scheduler();
        $scheduler->delay(0.5, function() use (&$called) {
            $called = true;
        });
        $scheduler->run();
        $this->assertTrue($called);
    }
}
