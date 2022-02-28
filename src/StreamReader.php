<?php

namespace bronsted;

use Psr\Http\Message\StreamInterface;
use function feof;
use function fread;
use function strlen;
use const PHP_EOL;

class StreamReader
{
    private mixed $stream;
    private Scheduler $scheduler;

    public function __construct(mixed $stream)
    {
        $this->stream = $stream;
        $this->scheduler = Scheduler::instance();
    }

    public function close()
    {
        fclose($this->stream);
    }

    public function readLine(): string
    {
        $result = '';
        $stop = false;
        while(!$stop && !feof($this->stream)) {
            $byte = fread($this->stream, 1);
            if (strlen($byte) == 0) {
                $this->wait();
            }
            else {
                $result .= $byte;
                $stop = $result[-1] == "\n";
            }
        }
        return $result;
    }

    public function read(int $length): string
    {
        $result = '';
        $pos = 0;
        while($pos < $length && !feof($this->stream)) {
            $bytes = fread($this->stream, $length - $pos);
            if (strlen($bytes) == 0) {
                $this->wait();
            }
            else {
                $result .= $bytes;
                $pos = strlen($result);
            }
        }
        return $result;
    }

    public function copyTo(StreamInterface $destination, int $length)
    {
        $pos = 0;
        while($pos < $length && !feof($this->stream)) {
            $written = $destination->write(fread($this->stream, $length - $pos));
            if ($written == 0) {
                $this->wait();
            }
            else {
                $pos = $destination->tell();
            }
        }
    }

    public function copyAllTo(StreamInterface $destination)
    {
        while(!$destination->eof() && !feof($this->stream)) {
            $written = $destination->write(fread($this->stream, 8 * 1024));
            if ($written == 0) {
                $this->wait();
            }
        }
    }

    private function wait()
    {
        $suspend = new Suspend();
        $this->scheduler->onReadable($this->stream, function() use($suspend) {
            $suspend->done();
        });
        $suspend->wait();
    }
}