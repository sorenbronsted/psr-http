<?php

namespace bronsted;

use Exception;
use Psr\Http\Message\StreamInterface;
use function feof;
use function fread;
use function fwrite;
use function hexdec;
use function strlen;
use function substr;
use function trim;

class StreamSocket
{
    private mixed $stream;
    private FiberLoop $loop;

    public function __construct(mixed $stream)
    {
        $this->stream = $stream;
        $this->loop = FiberLoop::instance();
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
            $byte = @fread($this->stream, 1);
            if ($byte === false) {
                throw new Exception('fread failed');
            }
            if (strlen($byte) == 0) {
                $this->waitRead();
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
            $bytes = @fread($this->stream, $length - $pos);
            if ($bytes === false) {
                throw new Exception('fread failed');
            }
            if (strlen($bytes) == 0) {
                $this->waitRead();
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
            $bytes = @fread($this->stream, $length - $pos);
            if ($bytes === false) {
                throw new Exception('fread failed');
            }
            $written = $destination->write($bytes);
            if ($written == 0) {
                $this->waitRead();
            }
            else {
                $pos = $destination->tell();
            }
        }
    }

    public function copyChunkedTo(StreamInterface $destination)
    {
        // https://en.wikipedia.org/wiki/Chunked_transfer_encoding
        while(!feof($this->stream)) {
            $length = hexdec(trim($this->readLine()));
            if ($length <= 0) {
                return;
            }
            $line = trim($this->read($length + 2)); // inclusive \r\n
            $destination->write($line);
        }
    }

    public function write(string $string)
    {
        $length = strlen($string);
        $pos = 0;
        while ($pos < $length && !feof($this->stream)) {
            $written = @fwrite($this->stream, substr($string, $pos, $length - $pos));
            if ($written === false) {
                throw new Exception('fwrite failed');
            }
            if ($written == 0) {
                $this->waitWrite();
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
            $written = @fwrite($this->stream, $source->read($source->getSize() - $pos));
            if ($written === false) {
                throw new Exception('fwrite failed');
            }
            if ($written == 0) {
                $this->waitWrite();
            }
            $pos += $written;
        }
    }

    private function waitRead()
    {
        $suspend = new Suspend();
        $this->loop->onReadableOnce($this->stream, function() use($suspend) {
            $suspend->done();
        });
        $suspend->wait();
    }

    private function waitWrite()
    {
        $suspend = new Suspend();
        $this->loop->onWriteableReady($this->stream, function() use($suspend) {
            $suspend->done();
        });
        $suspend->wait();
    }
}