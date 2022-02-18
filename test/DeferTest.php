<?php

use bronsted\Defer;
use bronsted\Scheduler;
use PHPUnit\Framework\TestCase;

class DeferTest extends TestCase
{
    public function testOk()
    {
        $called = false;
        $scheduler = new Scheduler();
        $scheduler->defer(function() use (&$called) {
            $called = true;
        });
        $scheduler->run();
        $this->assertTrue($called);
    }
}
