<?php

namespace bronsted;

use Nyholm\Psr7\Factory\Psr17Factory;

function stream_socket_client()
{
    return tmpfile();
}

function gethostbyname()
{
    return '127.0.0.1';
}

class HttpClientTest extends TestCase
{
    public function testSendRequest()
    {
        $streamSocketMock = $this->mock(StreamSocket::class);
        $streamSocketMock->method('write');
        $streamSocketMock->method('copyFrom');
        $streamSocketMock->method('readLine')->willReturn(
            'HTTP/1 200',
            'content-length:0',
            ''
        );
        $streamSocketMock->method('close');

        $streamSocketFactoryMock = $this->mock(StreamSocketFactory::class);
        $streamSocketFactoryMock->method('createStreamSocket')->willReturn($streamSocketMock);

        $loop = FiberLoop::instance();
        $loop->defer(function() use($streamSocketFactoryMock) {
            $factory = new Psr17Factory();
            $request = $factory->createRequest('POST', 'http://somewhere.net');
            $request = $request->withBody($factory->createStream('test'));
            $client = new HttpClient($factory, $streamSocketFactoryMock, $this->logger);
            $response = $client->sendRequest($request);
            $this->assertEquals(200, $response->getStatusCode());
        });
        $loop->run();
        $this->assertTrue($this->logger->hasDebugRecords());
    }
}
