<?php

use bronsted\HttpServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use function Brain\Monkey\Functions\when;
use function Brain\Monkey\tearDown;

class TestHttpServer extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        tearDown();
        parent::tearDown();
    }

    public function testStartAndStop()
    {
        $called = false;
        $fh = tmpfile();
        fwrite($fh, "POST / HTTP/1.1\r\n\r\n");
        rewind($fh);
        when('stream_socket_server')->justReturn($fh);
        when('stream_socket_accept')->returnArg(1);

        $factory = new Psr17Factory();
        $server = new HttpServer('0.0.0.0', 8000, $factory, $factory, $factory, $factory, $factory);
        $self = $this;
        $server->run(function(ServerRequestInterface $request) use ($factory, &$called, $self) {
            $self->assertEmpty($request->getHeaders());
            $self->assertEmpty($request->getBody()->getContents());
            $called = true;
            return $factory->createResponse();
        });
        $this->assertTrue($called);
    }
}