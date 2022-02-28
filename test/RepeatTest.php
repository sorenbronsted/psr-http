<?php

use bronsted\Scheduler;
use PHPUnit\Framework\TestCase;

class RepeatTest extends TestCase
{
    public function testOk()
    {
        $called = 0;
        $scheduler = new Scheduler();
        $scheduler->repeat(0.1, function($interval) use (&$called) {
            $called += 1;
            if ($called > 1) {
                return 0;
            }
            return $interval;
        });
        $scheduler->run();
        $this->assertEquals(2, $called);
    }
}
