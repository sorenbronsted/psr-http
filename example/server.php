<?php
require __DIR__ . '/../vendor/autoload.php';

use bronsted\HttpServer;
use bronsted\FiberLoop;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

$factory = new Psr17Factory();
$server = new HttpServer('0.0.0.0', 8000, $factory, $factory, $factory, $factory, $factory);

FiberLoop::instance()->repeat(2, function () {
    $memory = memory_get_usage() / 1024;
    $peek = memory_get_peak_usage() / 1024;
    $memoryFmt = number_format($memory) . ' Kb';
    $peekFmt = number_format($peek) . ' Kb';
    echo "Current memory usage: {$memoryFmt} peek: {$peekFmt}\n";
});

$server->run(function (ServerRequestInterface $request) use ($factory) {
    $response = $factory->createResponse();
    $path = $request->getUri()->getPath();
    if ($path == '/') {
        $params = $request->getQueryParams();
        $response->getBody()->write('Hello ' . (empty($params) ? 'world' : $params['name']));
    } else {
        $response = $response->withStatus(404);
    }
    return $response;
});


