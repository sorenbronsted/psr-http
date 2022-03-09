# PSR compliant Http server and client

This is a http server and client package with [PSR](https://www.php-fig.org/) interfaces, so it can be used 
with frameworks which uses PSR interface. It is build with [FiberLoop](https://github.com/sorenbronsted/fiberloop) 
which is coroutines build on php fibers.

## Install
You can install this package via [Composer](http://getcomposer.org/):

`composer install bronsted\psr-http`

## Usage

Server usage:

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use bronsted\HttpServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

$factory = new Psr17Factory();
$server = new HttpServer('0.0.0.0', 8000, $factory, $factory, $factory, $factory, $factory);

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
```

Client usage without fiber context:

```php
<?php
require __DIR__ . '/vendor/autoload.php';

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
```

## Bugs and improvements
Report bugs in GitHub issues or feel free to make a pull request :-)

## License
MIT