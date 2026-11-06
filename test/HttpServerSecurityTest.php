<?php

namespace bronsted;

class HttpServerSecurityTest extends SocketTestCase
{
    private function configuredServer(array $options = []): HttpServer
    {
        return new HttpServer('127.0.0.1', 0, $this->factory, $this->factory, $this->factory,
            $this->factory, $this->factory, ...$options);
    }

    /** @dataProvider invalidHosts */
    public function testInvalidHostIsRejectedBeforeCallingTheHandler(string $headers, string $target = '/')
    {
        [$server, $client] = $this->pair();
        fwrite($client, "GET {$target} HTTP/1.1\r\n{$headers}\r\n");
        stream_socket_shutdown($client, STREAM_SHUT_WR);
        $called = false;
        $factory = $this->factory;
        $this->invoke($this->configuredServer(), 'work', new StreamSocket($server), function() use (&$called, $factory) {
            $called = true;
            return $factory->createResponse();
        });

        $this->assertStringStartsWith('HTTP/1.1 400 ', stream_get_contents($client));
        $this->assertFalse($called);
    }

    public static function invalidHosts(): array
    {
        return [
            'missing' => [''],
            'empty' => ["Host: \r\n"],
            'whitespace' => ["Host: \t \r\n"],
            'duplicate identical' => ["Host: example.test\r\nHost: example.test\r\n"],
            'duplicate different case' => ["Host: example.test\r\nhOsT: other.test\r\n"],
            'combined list' => ["Host: example.test,other.test\r\n"],
            'userinfo' => ["Host: user@example.test\r\n"],
            'path' => ["Host: example.test/path\r\n"],
            'query' => ["Host: example.test?q=x\r\n"],
            'fragment' => ["Host: example.test#fragment\r\n"],
            'internal whitespace' => ["Host: example .test\r\n"],
            'empty port' => ["Host: example.test:\r\n"],
            'negative port' => ["Host: example.test:-1\r\n"],
            'port overflow' => ["Host: example.test:65536\r\n"],
            'unbracketed IPv6' => ["Host: ::1\r\n"],
            'invalid IPv6' => ["Host: [12345::1]\r\n"],
            'invalid percent escape' => ["Host: example%xx.test\r\n"],
            'absolute target cannot supply missing Host' => ['', 'http://example.test/'],
            'absolute target cannot hide duplicate Host' => ["Host: example.test\r\nHost: other.test\r\n", 'http://example.test/'],
        ];
    }

    /** @dataProvider validHosts */
    public function testValidHostIsPreserved(string $host, string $target = '/')
    {
        [$server, $client] = $this->pair();
        fwrite($client, "GET {$target} HTTP/1.1\r\nhOsT: {$host}\r\n\r\n");
        stream_socket_shutdown($client, STREAM_SHUT_WR);
        $incoming = null;
        $factory = $this->factory;
        $this->invoke($this->configuredServer(), 'work', new StreamSocket($server), function($request) use (&$incoming, $factory) {
            $incoming = $request;
            return $factory->createResponse();
        });

        $this->assertStringStartsWith('HTTP/1.1 200 ', stream_get_contents($client));
        $this->assertSame([$host], $incoming->getHeader('Host'));
    }

    public static function validHosts(): array
    {
        return [
            ['localhost'], ['example.test'], ['Example.TEST:8080'], ['example.test.'],
            ['127.0.0.1:8000'], ['[::1]:8000'], ['[2001:db8::1]'], ['[::ffff:192.0.2.1]'],
            ['[v1.example]:80'], ['service_name'], ['exa%6dple.test'], ['example.test:000080'],
            ['example.test', 'http://example.test/'],
        ];
    }

    /** @dataProvider invalidLimits */
    public function testInvalidLimitsAreRejected(array $options)
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->configuredServer($options);
    }

    public static function invalidLimits(): array
    {
        return [
            [['headerTimeout' => 0.0]], [['headerTimeout' => -1.0]],
            [['headerTimeout' => INF]], [['headerTimeout' => NAN]],
            [['requestTimeout' => 0.0]], [['requestTimeout' => -1.0]],
            [['requestTimeout' => INF]], [['requestTimeout' => NAN]],
            [['maxConnections' => 0]], [['maxConnections' => -1]],
            [['maxParsedBodyBytes' => -1]],
        ];
    }

    /** @dataProvider bufferedContentTypes */
    public function testParsedBodyLimitRejectsDeclaredSizeBeforeReadingTheBody(string $type)
    {
        [$server, $client] = $this->pair();
        fwrite($client, "POST / HTTP/1.1\r\nHost: localhost\r\nContent-Type: {$type}\r\nContent-Length: 9\r\n\r\n");
        $called = false;
        $this->invoke($this->configuredServer(['maxParsedBodyBytes' => 8]), 'work', new StreamSocket($server),
            function() use (&$called) { $called = true; });

        $this->assertStringStartsWith('HTTP/1.1 413 ', stream_get_contents($client));
        $this->assertFalse($called);
    }

    /** @dataProvider bufferedContentTypes */
    public function testParsedBodyLimitAlsoAppliesToChunkedInput(string $type)
    {
        [$server, $client] = $this->pair();
        fwrite($client, "POST / HTTP/1.1\r\nHost: localhost\r\nContent-Type: {$type}\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "4\r\n1234\r\n5\r\n56789\r\n0\r\n\r\n");
        stream_socket_shutdown($client, STREAM_SHUT_WR);
        $called = false;
        $this->invoke($this->configuredServer(['maxParsedBodyBytes' => 8]), 'work', new StreamSocket($server),
            function() use (&$called) { $called = true; });

        $this->assertStringStartsWith('HTTP/1.1 413 ', stream_get_contents($client));
        $this->assertFalse($called);
    }

    public static function bufferedContentTypes(): array
    {
        return [
            ['application/json'], ['Application/JSON; charset=utf-8'], ['application/problem+json'],
            ['multipart/form-data; boundary=test'],
        ];
    }

    public function testJsonAtTheParsingLimitPreservesBothParsedAndRawBody()
    {
        [$server, $client] = $this->pair();
        $json = '{"a":1}';
        fwrite($client, "POST / HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: 7\r\n\r\n" . $json);
        stream_socket_shutdown($client, STREAM_SHUT_WR);
        $incoming = null;
        $factory = $this->factory;
        $this->invoke($this->configuredServer(['maxParsedBodyBytes' => 7]), 'work', new StreamSocket($server),
            function($request) use (&$incoming, $factory) {
                $incoming = $request;
                return $factory->createResponse();
            });

        $this->assertStringStartsWith('HTTP/1.1 200 ', stream_get_contents($client));
        $this->assertSame(1, $incoming->getParsedBody()->a);
        $this->assertSame($json, $incoming->getBody()->getContents());
    }

    /** @dataProvider unparsedBodies */
    public function testRawBodiesAreNotSubjectToTheParsingLimit(string $type, bool $parseBody)
    {
        [$server, $client] = $this->pair();
        fwrite($client, "POST / HTTP/1.1\r\nHost: localhost\r\nContent-Type: {$type}\r\nContent-Length: 8\r\n\r\nnot-json");
        stream_socket_shutdown($client, STREAM_SHUT_WR);
        $incoming = null;
        $factory = $this->factory;
        $this->invoke($this->configuredServer(['maxBodyBytes' => 8, 'maxParsedBodyBytes' => 0, 'parseBody' => $parseBody]),
            'work', new StreamSocket($server), function($request) use (&$incoming, $factory) {
                $incoming = $request;
                return $factory->createResponse();
            });

        $this->assertStringStartsWith('HTTP/1.1 200 ', stream_get_contents($client));
        $this->assertNull($incoming->getParsedBody());
        $this->assertSame([], $incoming->getUploadedFiles());
        $this->assertSame('not-json', $incoming->getBody()->getContents());
    }

    public static function unparsedBodies(): array
    {
        return [['application/octet-stream', true], ['application/json', false], ['multipart/form-data', false]];
    }

    public function testParsingLimitCannotIncreaseTheGeneralBodyLimit()
    {
        [$server, $client] = $this->pair();
        fwrite($client, "POST / HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: 7\r\n\r\n");
        $this->invoke($this->configuredServer(['maxBodyBytes' => 4, 'maxParsedBodyBytes' => 8]), 'work', new StreamSocket($server),
            function() { $this->fail('Oversized request must not reach the handler'); });
        $this->assertStringStartsWith('HTTP/1.1 413 ', stream_get_contents($client));
    }

    private function delayedRequest(string $initial, array $chunks, array $options): array
    {
        [$server, $client] = $this->pair();
        fwrite($client, $initial);
        $response = '';
        $called = false;
        $elapsed = null;
        $loop = FiberLoop::instance();
        $started = hrtime(true) / 1e9;
        $loop->onReadable($client, function($stream) use (&$response) {
            $response .= fread($stream, 8192);
            if (feof($stream)) {
                fclose($stream);
            }
        });
        $loop->defer(function() use ($server, $options, &$called, &$elapsed, $started) {
            $factory = $this->factory;
            $this->invoke($this->configuredServer($options), 'work', new StreamSocket($server), function() use (&$called, $factory) {
                $called = true;
                return $factory->createResponse();
            });
            $elapsed = hrtime(true) / 1e9 - $started;
        });
        foreach ($chunks as [$delay, $bytes]) {
            $loop->delay($delay, function() use ($client, $bytes) {
                if (is_resource($client)) {
                    @fwrite($client, $bytes);
                }
            });
        }
        $loop->run();

        return [$response, $called, $elapsed];
    }

    public function testHeaderDeadlineIsNotResetByIncomingBytes()
    {
        [$response, $called, $elapsed] = $this->delayedRequest(
            "GET / HTTP/1.1\r\nHost: localhost\r\nX-Slow: ",
            [[0.02, 'a'], [0.04, 'b'], [0.06, 'c'], [0.14, "\r\n\r\n"]],
            ['headerTimeout' => 0.1, 'requestTimeout' => 1.0],
        );
        $this->assertStringStartsWith('HTTP/1.1 408 ', $response);
        $this->assertFalse($called);
        $this->assertLessThan(0.14, $elapsed);
    }

    public function testRequestDeadlineIncludesTimeSpentReadingHeadersAndBody()
    {
        [$response, $called, $elapsed] = $this->delayedRequest(
            "POST / HTTP/1.1\r\nHost: localhost\r\n",
            [[0.04, "Content-Length: 4\r\n\r\n"], [0.06, 'a'], [0.08, 'b'], [0.1, 'c'], [0.18, 'd']],
            ['headerTimeout' => 0.3, 'requestTimeout' => 0.14],
        );
        $this->assertStringStartsWith('HTTP/1.1 408 ', $response);
        $this->assertFalse($called);
        $this->assertLessThan(0.18, $elapsed);
    }

    public function testChunkTrailersAreSubjectToTheRequestDeadline()
    {
        [$response, $called] = $this->delayedRequest(
            "POST / HTTP/1.1\r\nHost: localhost\r\nTransfer-Encoding: chunked\r\n\r\n0\r\nX-Slow: ",
            [[0.02, 'a'], [0.04, 'b'], [0.06, 'c'], [0.14, "\r\n\r\n"]],
            ['headerTimeout' => 0.05, 'requestTimeout' => 0.1],
        );
        $this->assertStringStartsWith('HTTP/1.1 408 ', $response);
        $this->assertFalse($called);
    }

    public function testBodyCanFinishAfterTheHeaderDeadlineButBeforeTheRequestDeadline()
    {
        [$response, $called] = $this->delayedRequest(
            "POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 3\r\n\r\n",
            [[0.03, 'a'], [0.06, 'b'], [0.09, 'c']],
            ['headerTimeout' => 0.05, 'requestTimeout' => 0.3],
        );
        $this->assertStringStartsWith('HTTP/1.1 200 ', $response);
        $this->assertTrue($called);
    }

    public function testOverallDeadlineCanBeShorterThanTheHeaderDeadline()
    {
        [$response, $called] = $this->delayedRequest(
            "GET / HTTP/1.1\r\nHost: localhost\r\nX-Slow: ",
            [[0.02, 'a'], [0.04, 'b'], [0.1, "\r\n\r\n"]],
            ['headerTimeout' => 1.0, 'requestTimeout' => 0.07],
        );
        $this->assertStringStartsWith('HTTP/1.1 408 ', $response);
        $this->assertFalse($called);
    }

    public function testDeadlineStartsAtAcceptRatherThanWhenTheWorkerRuns()
    {
        [$server, $client] = $this->pair();
        fwrite($client, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $this->invoke($this->configuredServer(['headerTimeout' => 0.1]), 'work', new StreamSocket($server),
            function() { $this->fail('Expired connection must not reach the handler'); }, hrtime(true) / 1e9 - 0.2);
        $this->assertStringStartsWith('HTTP/1.1 408 ', stream_get_contents($client));
    }
}
