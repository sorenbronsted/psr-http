<?php

require __DIR__ . '/../vendor/autoload.php';

use bronsted\HttpClient;
use bronsted\Scheduler;
use Nyholm\Psr7\Factory\Psr17Factory;

Scheduler::instance()->defer(function() use($argv) {
    $factory = new Psr17Factory();
    $request = $factory->createRequest('GET', $argv[1]);
    $client = new HttpClient($factory);
    $response = $client->fetch($request);

//var_dump($response->getHeaders());
// foreach ($response->getHeaders() as $name => $values) {
//     echo $name . ': ' . implode(' ', $values) . PHP_EOL;
// }
    echo $response->getStatusCode() . PHP_EOL;
    echo $response->getBody()->getContents() . PHP_EOL;
});

Scheduler::instance()->run();
