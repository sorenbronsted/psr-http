<?php

require __DIR__ . '/../vendor/autoload.php';

use bronsted\HttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;

$factory = new Psr17Factory();

$request = $factory->createRequest('GET', $argv[1]);

$client = new HttpClient($factory);

$response = $client->fetch($request);

//var_dump($response->getHeaders());
// foreach ($response->getHeaders() as $name => $values) {
//     echo $name . ': ' . implode(' ', $values) . PHP_EOL;
// }
echo $response->getStatusCode() . PHP_EOL;
echo strval($response->getBody()) . PHP_EOL;