<?php

namespace bronsted;

use Nyholm\Psr7\Factory\Psr17Factory;
use function Brain\Monkey\Functions\when;
use function Brain\Monkey\tearDown;

class HttpClientTest extends TestCase
{
    protected function tearDown(): void
    {
        tearDown();
        parent::tearDown();
    }

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

        $fh = tmpfile();
        when('gethostbyname')->justReturn('127.0.0.1');
        when('stream_socket_client')->justReturn($fh);

        FiberLoop::instance()->defer(function() use($streamSocketFactoryMock) {
            $factory = new Psr17Factory();
            $request = $factory->createRequest('POST', 'http://somewhere.net');
            $request = $request->withBody($factory->createStream('test'));
            $client = new HttpClient($factory, $streamSocketFactoryMock);
            $response = $client->sendRequest($request);
            $this->assertEquals(200, $response->getStatusCode());
        });
        FiberLoop::instance()->run();
    }
}
