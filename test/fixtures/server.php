<?php

namespace bronsted {
    // Only the bind step is supplied by the fixture, so the parent can learn the
    // OS-assigned port before connecting. Accept/read/write use real TCP sockets.
    function stream_socket_server($address)
    {
        return $GLOBALS['test_listener'];
    }
}

namespace {
    require __DIR__ . '/../../vendor/autoload.php';
    $listener = \stream_socket_server('tcp://127.0.0.1:0');
    $GLOBALS['test_listener'] = $listener;
    $address = stream_socket_get_name($listener, false);
    echo substr(strrchr($address, ':'), 1), "\n";
    fflush(STDOUT);
    $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
    $server = new \bronsted\HttpServer('127.0.0.1', 0, $factory, $factory, $factory, $factory, $factory);
    $server->run(function($request) use ($factory, $listener) {
        $path = $request->getUri()->getPath();
        if ($path === '/fail') {
            throw new \RuntimeException('request failed');
        }
        if ($path === '/stop') {
            fclose($listener);
        }
        $body = $path === '/echo' ? $request->getBody()->getContents() : 'ok';
        return $factory->createResponse()->withBody($factory->createStream($body));
    });
}
