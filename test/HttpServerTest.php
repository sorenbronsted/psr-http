<?php
namespace bronsted;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

function stream_socket_server()
{
    $fh = tmpfile();
    fwrite($fh, "POST / HTTP/1.1\r\n\r\n");
    rewind($fh);
    return $fh;
}

function stream_socket_accept($arg1)
{
    return $arg1;
}

class HttpServerTest extends TestCase
{
    public function testStartAndStop()
    {
        $called = false;
        $factory = new Psr17Factory();
        $server = new HttpServer('0.0.0.0', 8000, $factory, $factory, $factory, $factory, $factory, $this->logger);
        $self = $this;

        $server->run(function(ServerRequestInterface $request) use ($factory, &$called, $self) {
            $self->assertEmpty($request->getHeaders());
            $self->assertEmpty($request->getBody()->getContents());
            $called = true;
            return $factory->createResponse();
        });
        $this->assertTrue($called);
        $this->assertTrue($this->logger->hasDebugRecords());
    }
}