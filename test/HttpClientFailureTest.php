<?php

namespace bronsted;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\StreamInterface;
use function Brain\Monkey\Functions\when;

class HttpClientFailureTest extends TestCase
{
    private array $resources = [];

    protected function tearDown(): void
    {
        \Brain\Monkey\tearDown();
        foreach ($this->resources as $resource) {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
        parent::tearDown();
    }

    private function resource()
    {
        $resource = tmpfile();
        $this->resources[] = $resource;
        return $resource;
    }

    private function client(StreamSocket $socket): HttpClient
    {
        when('gethostbyname')->justReturn('127.0.0.1');
        when('stream_socket_client')->justReturn($this->resource());
        $factory = $this->createMock(StreamSocketFactory::class);
        $factory->method('createStreamSocket')->willReturn($socket);
        return new HttpClient(new Psr17Factory(), $factory);
    }

    /** @dataProvider failures */
    public function testFailuresUsePsrExceptionsAndCloseExactlyOnce(string $method, string $cause, string $expected)
    {
        $socket = $this->createMock(StreamSocket::class);
        $socket->expects($this->once())->method('close');
        $error = new $cause('failure');
        $socket->method($method)->willThrowException($error);
        $request = (new Psr17Factory())->createRequest('GET', 'http://example.test/');
        $client = $this->client($socket);

        try {
            $client->sendRequest($request);
            $this->fail('Expected a PSR-18 exception');
        }
        catch (ClientExceptionInterface $exception) {
            $this->assertInstanceOf($expected, $exception);
            $this->assertSame($request, $exception->getRequest());
            $this->assertSame($error, $exception->getPrevious());
        }
    }

    public static function failures(): array
    {
        return [
            'socket write' => ['write', SocketException::class, NetworkExceptionInterface::class],
            'body read' => ['copyFrom', \RuntimeException::class, RequestExceptionInterface::class],
            'socket read' => ['readLine', SocketException::class, NetworkExceptionInterface::class],
            'response parse' => ['readLine', \UnexpectedValueException::class, ClientExceptionInterface::class],
        ];
    }

    public function testDnsFailureImplementsNetworkExceptionInterface()
    {
        when('gethostbyname')->justReturn('missing.invalid');
        $factory = new Psr17Factory();
        $request = $factory->createRequest('GET', 'http://missing.invalid/');
        try {
            (new HttpClient($factory, new StreamSocketFactory()))->sendRequest($request);
            $this->fail('Expected DNS failure');
        }
        catch (NetworkExceptionInterface $error) {
            $this->assertSame($request, $error->getRequest());
            $this->assertInstanceOf(SocketException::class, $error->getPrevious());
        }
    }

    public function testConnectionFailureImplementsNetworkExceptionInterface()
    {
        when('gethostbyname')->justReturn('127.0.0.1');
        when('stream_socket_client')->justReturn(false);
        $this->expectException(NetworkExceptionInterface::class);
        $factory = new Psr17Factory();
        (new HttpClient($factory, new StreamSocketFactory()))
            ->sendRequest($factory->createRequest('GET', 'http://example.test/'));
    }

    public function testTruncatedResponseIsReportedAsNetworkFailure()
    {
        [$resource, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->resources[] = $resource;
        $this->resources[] = $peer;
        stream_set_blocking($resource, false);
        fwrite($peer, "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nab");
        stream_socket_shutdown($peer, STREAM_SHUT_WR);
        $client = $this->client(new StreamSocket($resource));
        $this->expectException(NetworkExceptionInterface::class);
        $client->sendRequest((new Psr17Factory())->createRequest('GET', 'http://example.test/'));
    }

    public function testPeerClosingBeforeResponseIsReportedAsNetworkFailure()
    {
        $socket = $this->createMock(StreamSocket::class);
        $socket->method('readLine')->willReturn('');
        $socket->expects($this->once())->method('close');
        $client = $this->client($socket);
        $this->expectException(NetworkExceptionInterface::class);
        $client->sendRequest((new Psr17Factory())->createRequest('GET', 'http://example.test/'));
    }

    public function testInvalidSchemeUsesRequestException()
    {
        $factory = new Psr17Factory();
        $this->expectException(RequestExceptionInterface::class);
        (new HttpClient($factory, new StreamSocketFactory()))
            ->sendRequest($factory->createRequest('GET', 'ftp://example.test/'));
    }

    public function testUnknownRequestBodySizeFailsWithRequestExceptionInsteadOfSendingUnframedData()
    {
        $factory = new Psr17Factory();
        $body = $this->createMock(StreamInterface::class);
        $body->method('getSize')->willReturn(null);
        $request = $factory->createRequest('POST', 'http://example.test/')->withBody($body);
        $this->expectException(RequestExceptionInterface::class);
        (new HttpClient($factory, new StreamSocketFactory()))->sendRequest($request);
    }

    public function testRequestSerializationReplacesContentLengthAndPreservesAuthorityPort()
    {
        $socket = $this->createMock(StreamSocket::class);
        $wire = '';
        $socket->method('write')->willReturnCallback(function($bytes) use (&$wire) { $wire .= $bytes; });
        $socket->method('readLine')->willReturn("HTTP/1.1 200 OK\r\n", "Content-Length: 0\r\n", "\r\n");
        $socket->expects($this->once())->method('close');
        $factory = new Psr17Factory();
        $request = $factory->createRequest('POST', 'http://example.test:8081/path?q=1')
            ->withHeader('Content-Length', '999')->withHeader('Transfer-Encoding', 'chunked')
            ->withBody($factory->createStream('abc'));
        $this->client($socket)->sendRequest($request);

        $this->assertStringStartsWith("POST /path?q=1 HTTP/1.1\r\n", $wire);
        $this->assertSame(1, substr_count(strtolower($wire), 'content-length:'));
        $this->assertStringContainsString("Content-Length: 3\r\n", $wire);
        $this->assertStringContainsString("Host: example.test:8081\r\n", $wire);
        $this->assertStringNotContainsString('Transfer-Encoding:', $wire);
    }
}
