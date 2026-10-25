<?php

namespace bronsted;

use Psr\Http\Message\StreamInterface;

class StreamSocketTest extends SocketTestCase
{
    public function testChunkedBodyPreservesEveryByteAndConsumesTrailers()
    {
        $payload = " \0hello\t\r\n";
        $wire = dechex(strlen($payload)) . ";name=value\r\n" . $payload
            . "\r\n0\r\nX-Checksum: test\r\n\r\nNEXT\r\n";
        $socket = $this->input($wire);
        $body = $this->factory->createStream();
        $socket->copyChunkedTo($body);

        $this->assertSame($payload, (string) $body);
        $this->assertSame("NEXT\r\n", $socket->readLine());
    }

    /** @dataProvider invalidChunks */
    public function testInvalidOrTruncatedChunksAreRejected(string $wire, string $exception)
    {
        $this->expectException($exception);
        $this->input($wire)->copyChunkedTo($this->factory->createStream());
    }

    public static function invalidChunks(): array
    {
        return [
            'invalid size' => ["nope\r\n", \UnexpectedValueException::class],
            'negative size' => ["-1\r\n", \UnexpectedValueException::class],
            'overflow' => ["ffffffffffffffffffffffff\r\n", \LengthException::class],
            'truncated data' => ["5\r\nab", SocketException::class],
            'bad terminator' => ["1\r\nx!!", \UnexpectedValueException::class],
            'missing trailers' => ["0\r\n", SocketException::class],
            'bad trailer' => ["0\r\nnot-a-header\r\n\r\n", \UnexpectedValueException::class],
        ];
    }

    public function testChunkedLimitAppliesToTheTotalDecodedBody()
    {
        $this->expectException(\LengthException::class);
        $this->input("2\r\nab\r\n2\r\ncd\r\n0\r\n\r\n")
            ->copyChunkedTo($this->factory->createStream(), 3);
    }

    public function testReadRequiresAllDeclaredBytes()
    {
        $this->expectException(SocketException::class);
        $this->input('ab')->read(5);
    }

    public function testCopyRequiresAllDeclaredBytes()
    {
        $this->expectException(SocketException::class);
        $this->input('ab')->copyTo($this->factory->createStream(), 5);
    }

    public function testCopyHandlesPartialDestinationWritesWithoutUsingAbsolutePosition()
    {
        $received = 'prefix';
        $destination = $this->createMock(StreamInterface::class);
        $destination->expects($this->never())->method('tell');
        $destination->method('write')->willReturnCallback(function($bytes) use (&$received) {
            $bytes = substr($bytes, 0, 2);
            $received .= $bytes;
            return strlen($bytes);
        });
        $this->input('hello')->copyTo($destination, 5);
        $this->assertSame('prefixhello', $received);
    }

    public function testDestinationThatMakesNoProgressIsRejected()
    {
        $destination = $this->createMock(StreamInterface::class);
        $destination->method('write')->willReturn(0);
        $this->expectException(\RuntimeException::class);
        $this->input('x')->copyTo($destination, 1);
    }

    public function testReadEofDoesNotPreventWritingOrCopyingResponse()
    {
        [$server, $client] = $this->pair();
        fwrite($client, 'request');
        stream_socket_shutdown($client, STREAM_SHUT_WR);
        stream_get_contents($server);
        $this->assertTrue(feof($server));
        $socket = new StreamSocket($server);
        $socket->write('response');
        $socket->copyFrom($this->factory->createStream(' body'));

        $this->assertSame('response body', stream_get_contents($client));
    }

    public function testLineLimitRejectsUnterminatedInputWithoutWaitingForMoreData()
    {
        [$server, $client] = $this->pair();
        fwrite($client, str_repeat('x', 32));
        $this->expectException(\LengthException::class);
        (new StreamSocket($server))->readLine(16);
    }

    public function testTruncatedLineIsRejected()
    {
        $this->expectException(SocketException::class);
        $this->input('unfinished')->readLine();
    }

    public function testCloseIsIdempotentAndUseAfterCloseThrows()
    {
        $socket = $this->input('x');
        $socket->close();
        $socket->close();
        $this->expectException(SocketException::class);
        $socket->write('x');
    }

    public function testCloseDelimitedReadWakesWhenPeerClosesAfterSendingData()
    {
        [$reader, $writer] = $this->pair();
        $socket = new StreamSocket($reader);
        $body = $this->factory->createStream();
        $loop = FiberLoop::instance();
        $loop->defer(fn() => $socket->copyUntilEof($body));
        $loop->delay(0.01, fn() => fwrite($writer, 'payload'));
        $loop->delay(0.02, fn() => fclose($writer));
        $loop->run();

        $this->assertSame('payload', (string) $body);
    }

    public function testLargeWriteHandlesBackpressureWithoutDroppingBytes()
    {
        [$writer, $reader] = $this->pair();
        $payload = str_repeat("\0binary data\n", 100000);
        $received = '';
        $socket = new StreamSocket($writer);
        $loop = FiberLoop::instance();
        $loop->onReadable($reader, function($stream) use (&$received, $payload) {
            $received .= fread($stream, 8192);
            if (strlen($received) === strlen($payload)) {
                fclose($stream);
            }
        });
        $loop->defer(function() use ($socket, $payload) {
            $socket->write($payload);
            $socket->close();
        });
        $loop->run();

        $this->assertSame(strlen($payload), strlen($received));
        $this->assertSame(hash('sha256', $payload), hash('sha256', $received));
    }

    public function testRepeatedWaitsReleaseFiberloopWatchers()
    {
        [$reader, $writer] = $this->pair();
        $socket = new StreamSocket($reader);
        $received = '';
        $loop = FiberLoop::instance();
        $loop->defer(function() use ($socket, &$received) {
            $received = $socket->read(3);
        });
        // Each byte arrives in a later loop tick, so every read blocks and has
        // to register a fresh watcher on the same resource.
        $loop->delay(0.01, fn() => fwrite($writer, 'a'));
        $loop->delay(0.03, fn() => fwrite($writer, 'b'));
        $loop->delay(0.05, fn() => fwrite($writer, 'c'));
        $loop->run();

        $this->assertSame('abc', $received);
    }

    public function testPeerCloseWhileWaitingFailsBeforeTheSuspendTimeout()
    {
        [$reader, $writer] = $this->pair();
        $socket = new StreamSocket($reader);
        $error = null;
        $start = microtime(true);
        $loop = FiberLoop::instance();
        $loop->defer(function() use ($socket, &$error) {
            try {
                $socket->read(5);
            }
            catch (\Throwable $e) {
                $error = $e;
            }
        });
        $loop->delay(0.02, fn() => fclose($writer));
        $loop->run();
        $elapsed = microtime(true) - $start;

        $this->assertInstanceOf(SocketException::class, $error);
        $this->assertLessThan(1.0, $elapsed, 'EOF should not wait for the Suspend timeout');
    }

    public function testStoppingTheLoopCancelsAPendingWait()
    {
        [$reader, $writer] = $this->pair();
        $socket = new StreamSocket($reader);
        $error = null;
        $loop = FiberLoop::instance();
        $loop->defer(function() use ($socket, &$error) {
            try {
                $socket->read(1);
            }
            catch (\Throwable $e) {
                $error = $e;
            }
        });
        $loop->delay(0.02, fn() => $loop->stop());
        $loop->run();

        $this->assertInstanceOf(CancelledException::class, $error);
    }
}
