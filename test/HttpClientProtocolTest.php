<?php

namespace bronsted;

class HttpClientProtocolTest extends SocketTestCase
{
    private function parse(string $wire, string $method = 'GET', array $options = [])
    {
        return $this->invoke(new HttpClient($this->factory, new StreamSocketFactory(), ...$options), 'readReponse', $this->input($wire), $method);
    }

    public function testCloseDelimitedBodyIsReadUntilEof()
    {
        $response = $this->parse("HTTP/1.1 200 OK\r\nConnection: close\r\n\r\n hello\0\n");
        $this->assertSame(" hello\0\n", $response->getBody()->getContents());
    }

    public function testInterimResponsesAreSkippedUntilFinalResponse()
    {
        $response = $this->parse("HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 103 Early Hints\r\nLink: </style.css>\r\n\r\n"
            . "HTTP/1.1 200 Custom reason\r\nContent-Length: 5\r\nSet-Cookie: a=1\r\nSet-Cookie: b=2\r\n\r\nhello");
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Custom reason', $response->getReasonPhrase());
        $this->assertSame('hello', $response->getBody()->getContents());
        $this->assertSame(['a=1', 'b=2'], $response->getHeader('Set-Cookie'));
    }

    public function testDecodedChunkBodyAndHeadersAreConsistent()
    {
        $response = $this->parse("HTTP/1.1 200 OK\r\nTransfer-Encoding: Chunked\r\nTrailer: X-End\r\n\r\n"
            . "5\r\n hi \n\r\n0\r\nX-End: yes\r\n\r\n");
        $this->assertSame(" hi \n", $response->getBody()->getContents());
        $this->assertFalse($response->hasHeader('Transfer-Encoding'));
        $this->assertFalse($response->hasHeader('Trailer'));
        $this->assertSame('5', $response->getHeaderLine('Content-Length'));
    }

    public function testTruncatedContentLengthIsRejected()
    {
        $this->expectException(SocketException::class);
        $this->parse("HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nab");
    }

    /** @dataProvider invalidResponses */
    public function testInvalidResponseFramingIsRejected(string $wire)
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->parse($wire);
    }

    public static function invalidResponses(): array
    {
        return [
            ['not HTTP' . "\r\n\r\n"],
            ["HTTP/1.1 200 OK\r\nContent-Length: 2;2\r\n\r\n"],
            ["HTTP/1.1 200 OK\r\nContent-Length: 2, 3\r\n\r\n"],
            ["HTTP/1.1 200 OK\r\nContent-Length: 2\r\nTransfer-Encoding: chunked\r\n\r\n"],
            ["HTTP/1.1 200 OK\r\nTransfer-Encoding: gzip, chunked\r\n\r\n"],
            ["HTTP/1.1 101 Switching Protocols\r\n\r\n"],
            [str_repeat("HTTP/1.1 100 Continue\r\n\r\n", 17)],
        ];
    }

    /** @dataProvider bodylessResponses */
    public function testHeadAndBodylessStatusesDoNotWaitForAnAdvertisedBody(string $method, int $status)
    {
        $response = $this->parse('HTTP/1.1 ' . $status . " Status\r\nContent-Length: 999\r\n\r\n", $method);
        $this->assertSame('', $response->getBody()->getContents());
    }

    public static function bodylessResponses(): array
    {
        return [['HEAD', 200], ['GET', 204], ['GET', 205], ['GET', 304]];
    }

    public function testIdenticalRepeatedContentLengthsAreAccepted()
    {
        $response = $this->parse("HTTP/1.1 200 OK\r\nContent-Length: 2, 02\r\n\r\nok");
        $this->assertSame('ok', $response->getBody()->getContents());
    }

    public function testErrorStatusIsReturnedNormally()
    {
        $response = $this->parse("HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\n\r\n");
        $this->assertSame(500, $response->getStatusCode());
    }

    /** @dataProvider oversizedBodies */
    public function testResponseBodyLimitAppliesToEveryFramingMode(string $wire)
    {
        $this->expectException(\LengthException::class);
        $this->parse($wire, options: ['maxResponseBodyBytes' => 4]);
    }

    public static function oversizedBodies(): array
    {
        return [
            'declared size rejected without reading body' => ["HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\n"],
            'chunked total' => ["HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n2\r\nab\r\n3\r\ncde\r\n0\r\n\r\n"],
            'connection close' => ["HTTP/1.1 200 OK\r\n\r\nabcde"],
        ];
    }

    /** @dataProvider exactSizeBodies */
    public function testResponseBodyAtTheLimitIsAccepted(string $wire)
    {
        $response = $this->parse($wire, options: ['maxResponseBodyBytes' => 4]);
        $this->assertSame('abcd', $response->getBody()->getContents());
    }

    public static function exactSizeBodies(): array
    {
        return [
            ["HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\nabcd"],
            ["HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n2\r\nab\r\n2\r\ncd\r\n0\r\n\r\n"],
            ["HTTP/1.1 200 OK\r\n\r\nabcd"],
        ];
    }

    /** @dataProvider emptyBodies */
    public function testZeroResponseBodyLimitStillAllowsAnEmptyBody(string $wire)
    {
        $response = $this->parse($wire, options: ['maxResponseBodyBytes' => 0]);
        $this->assertSame('', $response->getBody()->getContents());
    }

    public static function emptyBodies(): array
    {
        return [
            ["HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"],
            ["HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n"],
            ["HTTP/1.1 200 OK\r\n\r\n"],
        ];
    }

    public function testDefaultResponseBodyLimitIsEightMiB()
    {
        $this->expectException(\LengthException::class);
        $this->parse("HTTP/1.1 200 OK\r\nContent-Length: 8388609\r\n\r\n");
    }

    public function testResponseHeaderLimitIsConfigurable()
    {
        $this->expectException(\LengthException::class);
        $this->parse("HTTP/1.1 200 OK\r\nX-Large: 1234567890\r\n\r\n", options: ['maxResponseHeaderBytes' => 16]);
    }

    public function testBodylessResponseDoesNotRejectTheRepresentationsAdvertisedSize()
    {
        $response = $this->parse("HTTP/1.1 200 OK\r\nContent-Length: 999999\r\n\r\n", 'HEAD', ['maxResponseBodyBytes' => 0]);
        $this->assertSame('', $response->getBody()->getContents());
    }

    /** @dataProvider invalidLimits */
    public function testInvalidClientLimitsAreRejected(array $options)
    {
        $this->expectException(\InvalidArgumentException::class);
        new HttpClient($this->factory, new StreamSocketFactory(), ...$options);
    }

    public static function invalidLimits(): array
    {
        return [
            [['timeout' => -1]], [['maxResponseBodyBytes' => -1]], [['maxResponseHeaderBytes' => 1]],
            [['requestTimeout' => 0.0]], [['requestTimeout' => -1.0]],
            [['requestTimeout' => INF]], [['requestTimeout' => NAN]],
        ];
    }
}
