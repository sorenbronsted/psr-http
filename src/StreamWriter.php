<?php

namespace bronsted;

use Psr\Http\Message\StreamInterface;
use function fclose;
use function feof;
use function fwrite;
use function strlen;
use function substr;

class StreamWriter
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

    public function write(string $string)
    {
        $length = strlen($string);
        $pos = 0;
        while ($pos < $length && !feof($this->stream)) {
            $written = fwrite($this->stream, substr($string, $pos, $length - $pos));
            if ($written == 0) {
                $this->wait();
            }
            $pos += $written;
        }
    }

    public function copyFrom(StreamInterface $source)
    {
        $source->rewind();
        $pos = 0;
        while (!$source->eof() && $pos < $source->getSize() && !feof($this->stream)) {
            $source->seek($pos);
            $written = fwrite($this->stream, $source->read($source->getSize() - $pos));
            if ($written == 0) {
                $this->wait();
            }
            $pos += $written;
        }
    }

    private function wait()
    {
        $suspend = new Suspend();
        $this->scheduler->onWriteable($this->stream, fn() => $suspend->done());
        $suspend->wait();
    }
}