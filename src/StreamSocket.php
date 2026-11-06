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
    private ?float $readDeadline = null;
    private ?float $writeDeadline = null;

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

    /** Set an absolute monotonic deadline in seconds (hrtime(true) / 1e9). */
    public function setReadDeadline(?float $deadline): void
    {
        if ($deadline !== null && !is_finite($deadline)) {
            throw new \InvalidArgumentException('Read deadline must be finite');
        }
        $this->readDeadline = $deadline;
    }

    /** Set an absolute monotonic deadline for outgoing socket data. */
    public function setWriteDeadline(?float $deadline): void
    {
        if ($deadline !== null && !is_finite($deadline)) {
            throw new \InvalidArgumentException('Write deadline must be finite');
        }
        $this->writeDeadline = $deadline;
    }

    /** Await completion of an asynchronous TCP connect before using the socket. */
    public function awaitConnection(float $deadline): void
    {
        $this->assertConnectionDeadline($deadline);
        $this->wait(false, $deadline);
        $this->assertConnectionDeadline($deadline);
        if (@stream_socket_get_name($this->stream, true) === false) {
            throw new SocketException('Unable to complete TCP connection');
        }
    }

    /** Enable verified TLS on a connected non-blocking TCP socket. */
    public function enableTls(float $deadline): void
    {
        while (true) {
            $this->assertOpen();
            $this->assertConnectionDeadline($deadline);
            $result = @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($result === true) {
                return;
            }
            if ($result === false) {
                throw new SocketException('TLS handshake failed');
            }

            $remaining = $deadline - hrtime(true) / 1e9;
            $this->assertConnectionDeadline($deadline);
            $suspend = new Suspend($remaining);
            $ready = fn() => $suspend->done();
            $watcher = $this->loop->onReadable($this->stream, $ready);
            // PHP does not expose OpenSSL's WANT_READ/WANT_WRITE state. Read
            // readiness wakes the usual case; a bounded retry also covers a
            // handshake waiting to write, without watching an always-writable
            // socket and spinning the loop. No application data is sent yet.
            try {
                $this->loop->delay(min(0.01, $remaining), $ready);
                $suspend->wait();
            }
            catch (CancelledException $error) {
                throw $error;
            }
            catch (Throwable $error) {
                throw new SocketException('Timed out during TLS handshake', 0, $error);
            }
            finally {
                $watcher->cancel();
            }
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

    public function copyUntilEof(StreamInterface $destination, int $maxLength = PHP_INT_MAX)
    {
        if ($maxLength < 0) {
            throw new \InvalidArgumentException('Body limit must be non-negative');
        }
        $total = 0;
        while (true) {
            $remaining = $maxLength - $total;
            // Read one extra byte at the boundary to distinguish an exact-size
            // body from an oversized body, without buffering the excess.
            $bytes = $this->readAvailable($remaining >= 8192 ? 8192 : $remaining + 1);
            if ($bytes === '') {
                return;
            }
            if (strlen($bytes) > $remaining) {
                throw new LengthException('Body too large', 413);
            }
            $this->writeDestination($destination, $bytes);
            $total += strlen($bytes);
        }
    }

    public function copyChunkedTo(StreamInterface $destination, int $maxLength = PHP_INT_MAX)
    {
        if ($maxLength < 0) {
            throw new \InvalidArgumentException('Body limit must be non-negative');
        }
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
            $this->assertWriteDeadline();
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
            $this->assertReadDeadline();
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

    private function assertReadDeadline(): void
    {
        if ($this->readDeadline !== null && hrtime(true) / 1e9 >= $this->readDeadline) {
            throw new SocketException('Request read deadline exceeded', 408);
        }
    }

    private function assertWriteDeadline(): void
    {
        if ($this->writeDeadline !== null && hrtime(true) / 1e9 >= $this->writeDeadline) {
            throw new SocketException('Write deadline exceeded');
        }
    }

    private function assertConnectionDeadline(float $deadline): void
    {
        if (!is_finite($deadline)) {
            throw new \InvalidArgumentException('Connection deadline must be finite');
        }
        if (hrtime(true) / 1e9 >= $deadline) {
            throw new SocketException('Connection deadline exceeded');
        }
    }

    private function wait(bool $read, ?float $connectionDeadline = null): void
    {
        $this->assertOpen();
        $timeout = $connectionDeadline === null ? 5.0 : max(0.0, $connectionDeadline - hrtime(true) / 1e9);
        $deadline = $read ? $this->readDeadline : $this->writeDeadline;
        if ($read) {
            $this->assertReadDeadline();
        }
        else {
            $this->assertWriteDeadline();
        }
        if ($deadline !== null) {
            $timeout = min($timeout, max(0.0, $deadline - hrtime(true) / 1e9));
        }
        $suspend = new Suspend($timeout);
        $ready = fn() => $suspend->done();
        // Fiberloop calls a read watcher one final time when the stream reaches
        // EOF, so no polling is needed to notice a peer closing its side.
        $watcher = $read
            ? $this->loop->onReadable($this->stream, $ready)
            : $this->loop->onWriteable($this->stream, $ready);
        try {
            $suspend->wait();
        }
        catch (CancelledException $error) {
            throw $error;
        }
        catch (Throwable $error) {
            throw new SocketException('Timed out waiting for socket', $read && $this->readDeadline !== null ? 408 : 0, $error);
        }
        finally {
            // Fiberloop allows only one read and one write watcher per resource,
            // so the watcher must be released before the next wait.
            $watcher->cancel();
        }
    }
}
