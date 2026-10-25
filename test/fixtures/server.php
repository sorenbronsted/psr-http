<?php

namespace bronsted {
    // Bind the real listening socket here, with the context HttpServer passes in,
    // so the parent can learn the OS-assigned port before the loop starts. This
    // also keeps the backlog configured by HttpServer under test.
    function stream_socket_server($address, &$errno = null, &$errstr = null, $flags = 0, $context = null)
    {
        $listener = \stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );
        $GLOBALS['test_listener'] = $listener;
        $name = stream_socket_get_name($listener, false);
        echo substr(strrchr($name, ':'), 1), "\n";
        fflush(STDOUT);

        return $listener;
    }
}

namespace {
    require __DIR__ . '/../../vendor/autoload.php';
    $factory = new \Nyholm\Psr7\Factory\Psr17Factory();
    $server = new \bronsted\HttpServer('127.0.0.1', 0, $factory, $factory, $factory, $factory, $factory);
    $server->run(function($request) use ($factory) {
        $path = $request->getUri()->getPath();
        if ($path === '/fail') {
            throw new \RuntimeException('request failed');
        }
        if ($path === '/stop') {
            fclose($GLOBALS['test_listener']);
        }
        $body = $path === '/echo' ? $request->getBody()->getContents() : 'ok';
        return $factory->createResponse()->withBody($factory->createStream($body));
    });
}
