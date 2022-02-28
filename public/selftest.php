<?php

require __DIR__ . '/../vendor/autoload.php';

use bronsted\HttpClient;
use bronsted\HttpServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

$factory = new Psr17Factory();
$server = new HttpServer('0.0.0.0', 8000, $factory, $factory, $factory, $factory, $factory);
$client = new HttpClient($factory);

$server->run(function (ServerRequestInterface $request) use($factory, $client) {
    $path = $request->getUri()->getPath();
    if ($path == '/') {
        $request = $factory->createRequest('GET', 'http://localhost:8000/hello?name=Kurt');
        //$request = $factory->createRequest('GET', 'http://bronsted.dk');
        return $client->fetch($request);
    } else if ($path == '/hello') {
        $response = $factory->createResponse();
        $params = $request->getQueryParams();
        $response->getBody()->write('Hello ' . (empty($params) ? 'world' : $params['name']));
    } else {
        $response = $factory->createResponse();
        $response = $response->withStatus(404);
    }
    return $response;
});
