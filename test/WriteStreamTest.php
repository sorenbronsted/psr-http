<?php
namespace  bronsted;

use bronsted\Scheduler;
use PHPUnit\Framework\TestCase;

class WriteStreamTest extends TestCase
{
    public function testOk()
    {
        $called = 0;
        $scheduler = new Scheduler();
        $resource = fopen('/tmp/sletmig', 'w+');
        stream_set_blocking($resource, false);
        $scheduler->onWriteable($resource, function($stream) use (&$called) {
            $writer = new StreamWriter($stream);
            $called += 1;
            if ($called > 1) {
                $writer->close();
            }
            else {
                $writer->write('hello');
            }
        });
        $scheduler->run();
        $this->assertTrue($called > 0);
    }
}
