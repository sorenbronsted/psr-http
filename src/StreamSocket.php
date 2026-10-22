<?php

namespace bronsted;

use LengthException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

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
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function readLine(int $maxLength = 8192): string
    {
        if ($maxLength < 1) {
            throw new \InvalidArgumentException('Line limit must be positive');
        }
        $result = '';
        while (true) {
            $byte = $this->readAvailable(1);
            if ($byte === '') {
                if ($result !== '') {
                    throw new SocketException('Unexpected EOF while reading a line');
                }
                return '';
            }
            $result .= $byte;
            if ($byte === "\n") {
                return $result;
            }
            if (strlen($result) >= $maxLength) {
                throw new LengthException('Line too long', 431);
            }
        }
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new \InvalidArgumentException('Length must be non-negative');
        }
        $result = '';
        while (strlen($result) < $length) {
            $bytes = $this->readAvailable(min(8192, $length - strlen($result)));
            if ($bytes === '') {
                throw new SocketException('Unexpected EOF while reading body');
            }
            $result .= $bytes;
        }
        return $result;
    }

    public function copyTo(StreamInterface $destination, int $length)
    {
        if ($length < 0) {
            throw new \InvalidArgumentException('Length must be non-negative');
        }
        while ($length > 0) {
            $bytes = $this->read(min(8192, $length));
            $this->writeDestination($destination, $bytes);
            $length -= strlen($bytes);
        }
    }

    public function copyUntilEof(StreamInterface $destination)
    {
        while (($bytes = $this->readAvailable(8192)) !== '') {
            $this->writeDestination($destination, $bytes);
        }
    }

    public function copyChunkedTo(StreamInterface $destination, int $maxLength = PHP_INT_MAX)
    {
        $total = 0;
        while (true) {
            $line = $this->readLine();
            if (!preg_match('/^([0-9a-fA-F]+)(?:;[^\r\n]*)?\r\n$/D', $line, $parts)) {
                throw new UnexpectedValueException('Invalid chunk size', 400);
            }
            $length = hexdec($parts[1]);
            if (!is_int($length) || $length > $maxLength - $total) {
                throw new LengthException('Body too large', 413);
            }
            if ($length === 0) {
                $remaining = 65536;
                do {
                    $line = $this->readLine(min(8192, $remaining));
                    $remaining -= strlen($line);
                    if ($line === '') {
                        throw new SocketException('Unexpected EOF in chunk trailers');
                    }
                    if ($line !== "\r\n" && (!str_ends_with($line, "\r\n") || !str_contains($line, ':'))) {
                        throw new UnexpectedValueException('Invalid chunk trailer', 400);
                    }
                    if ($remaining < 0 || ($remaining < 2 && $line !== "\r\n")) {
                        throw new LengthException('Trailers too large', 431);
                    }
                } while ($line !== "\r\n");
                return;
            }
            $this->copyTo($destination, $length);
            if ($this->read(2) !== "\r\n") {
                throw new UnexpectedValueException('Invalid chunk terminator', 400);
            }
            $total += $length;
        }
    }

    public function write(string $string)
    {
        $pos = 0;
        $length = strlen($string);
        while ($pos < $length) {
            $this->assertOpen();
            $written = @fwrite($this->stream, substr($string, $pos, min(8192, $length - $pos)));
            if ($written === false) {
                throw new SocketException('fwrite failed');
            }
            if ($written === 0) {
                $this->wait(false);
            }
            $pos += $written;
        }
    }

    public function copyFrom(StreamInterface $source)
    {
        if ($source->isSeekable()) {
            $source->rewind();
        }
        while (!$source->eof()) {
            $bytes = $source->read(8192);
            if ($bytes === '' && !$source->eof()) {
                throw new RuntimeException('Source stream made no progress');
            }
            $this->write($bytes);
        }
    }

    private function readAvailable(int $length): string
    {
        while (true) {
            $this->assertOpen();
            $bytes = @fread($this->stream, $length);
            if ($bytes === false) {
                throw new SocketException('fread failed');
            }
            if ($bytes !== '' || feof($this->stream)) {
                return $bytes;
            }
            $this->wait(true);
        }
    }

    private function writeDestination(StreamInterface $destination, string $bytes): void
    {
        $pos = 0;
        while ($pos < strlen($bytes)) {
            $written = $destination->write(substr($bytes, $pos));
            if ($written <= 0 || $written > strlen($bytes) - $pos) {
                throw new RuntimeException('Destination stream made no progress');
            }
            $pos += $written;
        }
    }

    private function assertOpen(): void
    {
        if (!is_resource($this->stream)) {
            throw new SocketException('Socket is closed');
        }
    }

    private function wait(bool $read): void
    {
        $suspend = new Suspend();
        $done = false;
        $ready = function() use ($suspend, &$done) {
            $done = true;
            $suspend->done();
        };
        if ($read) {
            $this->loop->onReadableOnce($this->stream, $ready);
        }
        else {
            $this->loop->onWriteableReady($this->stream, $ready);
        }
        // Fiberloop removes EOF read watchers without invoking their callbacks.
        // A bounded check also wakes the reader for EOF/closure, without busy-waiting
        // or depending on Fiberloop's internal suspension implementation.
        $this->loop->repeat(0.01, function() use ($ready, $read, &$done) {
            if ($done) {
                return 0;
            }
            if (!is_resource($this->stream) || ($read && feof($this->stream))) {
                $ready();
                return 0;
            }
            return null;
        });
        try {
            $suspend->wait();
        }
        catch (Throwable $error) {
            throw new SocketException('Timed out waiting for socket', 0, $error);
        }
        finally {
            $done = true;
        }
    }
}
