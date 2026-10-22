<?php

namespace bronsted;

class HttpClientProtocolTest extends SocketTestCase
{
    private function parse(string $wire, string $method = 'GET')
    {
        return $this->invoke(new HttpClient($this->factory, new StreamSocketFactory()), 'readReponse', $this->input($wire), $method);
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
}
