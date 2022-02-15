<?php
require __DIR__ . '/../vendor/autoload.php';

use bronsted\HttpServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Revolt\EventLoop;

$factory = new Psr17Factory();
$server = new HttpServer('0.0.0.0', 8000, $factory, $factory, $factory, $factory, $factory);

// EventLoop::repeat(5, function () {
//     $memory = memory_get_usage() / 1024;
//     $formatted = number_format($memory).' KiB';
//     echo "Current memory usage: {$formatted}\n";

//    // var_dump(EventLoop::getInfo());
// });

// EventLoop::onSignal(15, function() {
//     memprof_dump_callgrind(fopen("callgrind", "w"));
//     exit(0);
// });

$server->run(function (ServerRequestInterface $request) use($factory) {
    $response = $factory->createResponse();
    $path = $request->getUri()->getPath();
    if ($path == '/') {
        $params = $request->getQueryParams();
        $response->getBody()->write('Hello ' . (empty($params) ? 'world' : $params['name']));
    } else {
        $response = $response->withStatus(404);
    }
    //memprof_dump_pprof(fopen("profile.heap", "w"));
    return $response;
});


