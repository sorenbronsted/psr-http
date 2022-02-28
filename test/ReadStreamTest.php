<?php

use bronsted\Scheduler;
use bronsted\StreamReader;
use PHPUnit\Framework\TestCase;

class ReadStreamTest extends TestCase
{
    public function testOk()
    {
        $called = 0;
        $scheduler = new Scheduler();
        $resource = fopen(__DIR__ . '/data/dump', 'r');
        stream_set_blocking($resource, false);
        $scheduler->onReadable($resource, function($stream) use (&$called) {
            $reader = new StreamReader($stream);
            $called += 1;
            if ($called == 1) {
                $reader->readLine();
            }
            else {
                $reader->read(100);
            }
        });
        $scheduler->run();
        $this->assertEquals(6, $called);
    }
}
