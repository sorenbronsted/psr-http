<?php

namespace bronsted;

use Psr\Http\Message\StreamInterface;

class HttpServerProtocolTest extends SocketTestCase
{
    public function testGetFiberLoopReturnsTheServersExistingLoop()
    {
        $server = $this->server();

        $this->assertSame(FiberLoop::instance(), $server->getFiberLoop());
        $this->assertSame($server->getFiberLoop(), $server->getFiberLoop());
    }

    private function respond(string $wire, ?\Closure $handler = null, int $maxHeaders = 65536, int $maxBody = 8388608): string
    {
        [$server, $client] = $this->pair();
        fwrite($client, $wire);
        stream_socket_shutdown($client, STREAM_SHUT_WR);
        $factory = $this->factory;
        $handler ??= function() use ($factory) { return $factory->createResponse(); };
        $this->invoke($this->server($maxHeaders, $maxBody), 'work', new StreamSocket($server), $handler);
        $this->assertFalse(is_resource($server));
        return stream_get_contents($client);
    }

    /** @dataProvider handlerErrors */
    public function testHandlerFailureReturns500AndDoesNotEscape(string $class)
    {
        $response = $this->respond("GET / HTTP/1.1\r\n\r\n", function() use ($class) {
            throw new $class('handler failed');
        });
        $this->assertStringStartsWith('HTTP/1.1 500 ', $response);
        $this->assertStringContainsString("Content-Length: 0\r\n", $response);
    }

    public static function handlerErrors(): array
    {
        return [['RuntimeException'], ['TypeError']];
    }

    public function testWriteFailureClosesConnectionAndDoesNotEscape()
    {
        $stream = $this->createMock(StreamSocket::class);
        $stream->method('readLine')->willReturn("GET / HTTP/1.1\r\n", "\r\n");
        $stream->method('copyFrom')->willThrowException(new SocketException('broken pipe'));
        $stream->expects($this->once())->method('close');
        $factory = $this->factory;
        $this->invoke($this->server(), 'work', $stream, function() use ($factory) {
            return $factory->createResponse()->withBody($factory->createStream('x'));
        });

        $response = $this->respond("GET / HTTP/1.1\r\n\r\n");
        $this->assertStringStartsWith('HTTP/1.1 200 ', $response);
    }

    public function testResponseUsesSingleContentLengthAndSeparateCookieHeaders()
    {
        $factory = $this->factory;
        $response = $this->respond("GET / HTTP/1.1\r\n\r\n", function() use ($factory) {
            return $factory->createResponse()->withHeader('Content-Length', '99')
                ->withHeader('Transfer-Encoding', 'chunked')->withHeader('Connection', 'keep-alive')
                ->withAddedHeader('Set-Cookie', 'session=abc; HttpOnly')
                ->withAddedHeader('Set-Cookie', 'theme=dark')
                ->withBody($factory->createStream('ok'));
        });
        $this->assertSame(1, substr_count(strtolower($response), 'content-length:'));
        $this->assertStringContainsString("Content-Length: 2\r\n", $response);
        $this->assertStringContainsString("Set-Cookie: session=abc; HttpOnly\r\nSet-Cookie: theme=dark\r\n", $response);
        $this->assertStringContainsString("Connection: close\r\n", $response);
        $this->assertStringNotContainsString('Transfer-Encoding:', $response);
        $this->assertStringEndsWith("\r\n\r\nok", $response);
    }

    public function testQueryAndCookiesPreserveEncodedDelimitersAndEquals()
    {
        $factory = $this->factory;
        $request = null;
        $response = $this->respond("GET /?name=alice%26admin%3Dtrue&token=a%3Db&empty HTTP/1.1\r\n"
            . "Cookie: session=abc==; theme=dark; escaped=a%3Bb; plus=a+b\r\n\r\n",
            function($incoming) use ($factory, &$request) {
                $request = $incoming;
                return $factory->createResponse();
            });
        $this->assertStringStartsWith('HTTP/1.1 200 ', $response);
        $this->assertSame(['name' => 'alice&admin=true', 'token' => 'a=b', 'empty' => ''], $request->getQueryParams());
        $this->assertSame(['session' => 'abc==', 'theme' => 'dark', 'escaped' => 'a;b', 'plus' => 'a+b'], $request->getCookieParams());
        $this->assertTrue($request->hasHeader('Cookie'));
    }

    public function testBodyWithoutContentTypeIsDeliveredUnchanged()
    {
        $factory = $this->factory;
        $body = null;
        $this->respond("POST / HTTP/1.1\r\nContent-Length: 5\r\n\r\nhello", function($request) use ($factory, &$body) {
            $body = $request->getBody()->getContents();
            return $factory->createResponse();
        });
        $this->assertSame('hello', $body);
    }

    public function testChunkedRequestIsDecodedAndBounded()
    {
        $factory = $this->factory;
        $body = null;
        $this->respond("POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n3\r\n a \r\n0\r\n\r\n",
            function($request) use ($factory, &$body) {
                $body = $request->getBody()->getContents();
                return $factory->createResponse();
            });
        $this->assertSame(' a ', $body);
    }

    public function testJsonParsingDoesNotConsumeRawBody()
    {
        $factory = $this->factory;
        $request = null;
        $json = '{"name":"alice"}';
        $this->respond("POST / HTTP/1.1\r\nContent-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\n\r\n" . $json,
            function($incoming) use ($factory, &$request) {
                $request = $incoming;
                return $factory->createResponse();
            });
        $this->assertSame('alice', $request->getParsedBody()->name);
        $this->assertSame($json, $request->getBody()->getContents());
    }

    public function testMultipartParsingKeepsFieldsAndRawBody()
    {
        $factory = $this->factory;
        $request = null;
        $body = "--test-boundary\r\nContent-Disposition: form-data; name=\"name\"\r\n\r\nalice\r\n--test-boundary--\r\n";
        $this->respond("POST / HTTP/1.1\r\nContent-Type: multipart/form-data; boundary=test-boundary\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body,
            function($incoming) use ($factory, &$request) {
                $request = $incoming;
                return $factory->createResponse();
            });
        $this->assertSame(['name' => 'alice'], $request->getParsedBody());
        $this->assertSame($body, $request->getBody()->getContents());
    }

    public function testUnknownSizeResponseUsesConnectionCloseFraming()
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('getSize')->willReturn(null);
        $body->method('isSeekable')->willReturn(false);
        $body->method('eof')->willReturn(false, true);
        $body->method('read')->willReturn('payload');
        $factory = $this->factory;
        $response = $this->respond("GET / HTTP/1.1\r\n\r\n", function() use ($factory, $body) {
            return $factory->createResponse()->withHeader('Content-Length', '999')->withBody($body);
        });
        $this->assertStringNotContainsString('Content-Length:', $response);
        $this->assertStringContainsString("Connection: close\r\n", $response);
        $this->assertStringEndsWith("\r\n\r\npayload", $response);
    }

    /** @dataProvider invalidRequests */
    public function testBadOrOversizedInputIsRejectedWithoutCallingHandler(string $wire, int $status, int $headers, int $body)
    {
        $called = false;
        $response = $this->respond($wire, function() use (&$called) { $called = true; }, $headers, $body);
        $this->assertStringStartsWith('HTTP/1.1 ' . $status . ' ', $response);
        $this->assertFalse($called);
    }

    public static function invalidRequests(): array
    {
        return [
            'unterminated start line' => ['GET /' . str_repeat('x', 9000), 414, 65536, 8],
            'single large header' => ["GET / HTTP/1.1\r\nX: " . str_repeat('x', 8300) . "\r\n\r\n", 431, 65536, 8],
            'total headers' => ["GET / HTTP/1.1\r\nA: 123456\r\nB: 123456\r\n\r\n", 431, 20, 8],
            'declared body' => ["POST / HTTP/1.1\r\nContent-Length: 9\r\n\r\n", 413, 65536, 8],
            'chunked body' => ["POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n0\r\n\r\n", 413, 65536, 4],
            'short body' => ["POST / HTTP/1.1\r\nContent-Length: 5\r\n\r\nab", 400, 65536, 8],
            'malformed header' => ["GET / HTTP/1.1\r\nNo colon\r\n\r\n", 400, 65536, 8],
            'ambiguous framing' => ["POST / HTTP/1.1\r\nContent-Length: 5\r\nTransfer-Encoding: chunked\r\n\r\n", 400, 65536, 8],
            'conflicting lengths' => ["POST / HTTP/1.1\r\nContent-Length: 2\r\nContent-Length: 3\r\n\r\n", 400, 65536, 8],
            'invalid length' => ["POST / HTTP/1.1\r\nContent-Length: -1\r\n\r\n", 400, 65536, 8],
            'overflow length' => ["POST / HTTP/1.1\r\nContent-Length: 999999999999999999999\r\n\r\n", 413, 65536, 8],
            'header whitespace' => ["GET / HTTP/1.1\r\nHost : localhost\r\n\r\n", 400, 65536, 8],
            'invalid json' => ["POST / HTTP/1.1\r\nContent-Type: application/json\r\nContent-Length: 1\r\n\r\n!", 400, 65536, 8],
        ];
    }

    /** @dataProvider noBodyResponses */
    public function testResponsesThatMustNotHaveBodiesAreNotWritten(string $method, int $status)
    {
        $factory = $this->factory;
        $response = $this->respond($method . " / HTTP/1.1\r\n\r\n", function() use ($factory, $status) {
            return $factory->createResponse($status)->withBody($factory->createStream('hidden'));
        });
        $this->assertStringEndsWith("\r\n\r\n", $response);
        $this->assertStringNotContainsString('hidden', $response);
    }

    public static function noBodyResponses(): array
    {
        return [['HEAD', 200], ['GET', 204], ['GET', 304]];
    }
}
