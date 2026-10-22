<?php

namespace bronsted;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase as FrameworkTestCase;
use Psr\Log\LoggerInterface;

abstract class SocketTestCase extends FrameworkTestCase
{
    protected Psr17Factory $factory;
    private array $resources = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new Psr17Factory();
    }

    protected function tearDown(): void
    {
        foreach ($this->resources as $resource) {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
        parent::tearDown();
    }

    protected function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);
        foreach ($pair as $resource) {
            stream_set_blocking($resource, false);
            $this->resources[] = $resource;
        }
        return $pair;
    }

    protected function input(string $bytes): StreamSocket
    {
        $resource = tmpfile();
        $this->resources[] = $resource;
        fwrite($resource, $bytes);
        rewind($resource);
        return new StreamSocket($resource);
    }

    protected function invoke(object $object, string $method, ...$arguments): mixed
    {
        return (new \ReflectionMethod($object, $method))->invoke($object, ...$arguments);
    }

    protected function server(int $maxHeaders = 65536, int $maxBody = 8388608, ?LoggerInterface $log = null): HttpServer
    {
        return new HttpServer('127.0.0.1', 0, $this->factory, $this->factory, $this->factory,
            $this->factory, $this->factory, $log, maxHeaderBytes: $maxHeaders, maxBodyBytes: $maxBody);
    }
}
