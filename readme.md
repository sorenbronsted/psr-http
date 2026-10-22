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

Use `$server->getFiberLoop()` to access the same event loop used by the server.
Timers and deferred background work can be scheduled on it before calling `run()`.

Standalone client usage context:

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use bronsted\HttpClient;
use bronsted\FiberLoop;
use bronsted\StreamSocketFactory;
use Nyholm\Psr7\Factory\Psr17Factory;

FiberLoop::instance()->defer(function() use($argv) {
    $factory = new Psr17Factory();
    $request = $factory->createRequest('GET', $argv[1]);
    $client = new HttpClient($factory, new StreamSocketFactory());
    $response = $client->sendRequest($request);
    echo $response->getStatusCode() . PHP_EOL;
    echo $response->getBody()->getContents() . PHP_EOL;
});

FiberLoop::instance()->run();
```

## HTTP behavior and limits

The server handles one request per connection and sends `Connection: close`.
Exceptions from a request handler produce a `500` response; a disconnected client
or failed response write does not stop the server's event loop.

Request lines are limited to 8,000 bytes, individual header lines to 8 KiB, total
headers to 64 KiB, and decoded request bodies to 8 MiB. Limits are checked while
reading, including chunked bodies. Oversized request lines, headers, and bodies
produce `414`, `431`, and `413` responses respectively. The optional constructor
arguments `maxHeaderBytes` and `maxBodyBytes` override the total-header and body
limits. Ambiguous framing, such as both `Content-Length` and `Transfer-Encoding`,
is rejected rather than guessed.

The client reads fixed-length, chunked, and connection-close-delimited responses.
Informational responses are skipped until the final response; HEAD, 204, 205,
and 304 responses do not wait for a body. Truncated bodies are errors, and chunk
payloads are preserved byte-for-byte. Protocol upgrades (`101`) and transfer
codings other than `chunked` are not supported.

Client failures implement PSR-18's `ClientExceptionInterface`. Network failures
use `bronsted\NetworkException`, invalid or unreadable outgoing requests use
`bronsted\RequestException`, and invalid responses use `bronsted\ClientException`.
The original request and underlying exception are available through
`getRequest()` and `getPrevious()`. Outgoing request bodies must have a known
size; unknown-size bodies are rejected with a request exception rather than
sent without framing. Hostname resolution remains blocking.

On PHP 8.5 and newer, the server uses a bounded 1 ms non-blocking accept poll to
work around Fiberloop 2.x treating listening TCP sockets as EOF. Earlier runtimes
use readiness notifications. EOF checks for pending socket reads are timer-based,
so a peer closing its send direction also wakes the reader without busy-waiting.

## Tests

Run `composer test`, or `composer run coverage` with Xdebug coverage enabled.
The suite includes byte-preservation, HTTP framing, input-limit, error-isolation,
and local TCP integration tests. It does not require external HTTP services.

## Bugs and improvements
Report bugs in GitHub issues or feel free to make a pull request :-)

## License
MIT
