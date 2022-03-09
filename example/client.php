<?php

require __DIR__ . '/../vendor/autoload.php';

use bronsted\HttpClient;
use bronsted\FiberLoop;
use Nyholm\Psr7\Factory\Psr17Factory;

FiberLoop::instance()->defer(function() use($argv) {
    $factory = new Psr17Factory();
    $request = $factory->createRequest('GET', $argv[1]);
    $client = new HttpClient($factory);
    $response = $client->sendRequest($request);
    echo $response->getStatusCode() . PHP_EOL;
    echo $response->getBody()->getContents() . PHP_EOL;
});

FiberLoop::instance()->run();
