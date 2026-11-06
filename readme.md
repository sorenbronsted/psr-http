# PSR compliant Http server and client

This is a http server and client package with [PSR](https://www.php-fig.org/) interfaces, so it can be used 
with frameworks which uses PSR interface. It is build with [FiberLoop](https://github.com/sorenbronsted/fiberloop) 
which is coroutines build on php fibers.

Version 3 requires Fiberloop 3.1, whose event loop is built around read and write
watchers. `HttpServer::getFiberLoop()` returns that loop, so timers and deferred
work can be scheduled on the same loop.

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

The v2.0 logger and timeout constructor arguments remain supported. `HttpClient`
accepts an optional PSR-3 logger as its third argument and a connection timeout in
seconds as its fourth argument (default `5`). `HttpServer` accepts an optional
PSR-3 logger after its seven factory/address arguments, before the optional size
limits. Omitting the logger uses a `NullLogger`. Request timing and failures are
logged without allowing a logging backend failure to interrupt HTTP processing.
The standalone static `Log` helper remains available for compatibility.

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

HTTP/1.1 requests must contain exactly one non-empty, valid `Host` header.
Missing, duplicate, comma-combined or malformed values receive `400`, including
when an absolute-form request target would let a PSR-17 factory infer a host.
Registered names, IPv4, bracketed IPv6/IP literals and optional ports are supported.

The server also accepts these optional named constructor arguments:

| Argument | Default | Behavior |
| --- | --- | --- |
| `headerTimeout` | `10.0` seconds | Absolute deadline for receiving the request line and headers. |
| `requestTimeout` | `30.0` seconds | Absolute deadline for receiving the entire request, including headers, body and chunk trailers. |
| `maxConnections` | `256` | Maximum accepted connections being processed, including response writes. |
| `maxParsedBodyBytes` | `null` | Additional input-byte limit for automatic JSON and multipart parsing; `null` uses `maxBodyBytes`. |
| `parseBody` | `true` | Automatically populate parsed JSON/form fields and uploaded files; `false` leaves the bounded raw body for the application. |

Both deadlines start when the socket is accepted, not when its worker is scheduled.
Receiving another byte does not reset them. The earlier deadline applies while
reading headers; after that, only the overall request deadline applies. Expired
reads return `408`. These limits do not interrupt handler code or synchronous
parsing; the existing five-second socket I/O inactivity timeout remains in place.
Timeouts must be finite and positive, and `maxConnections` must be at least one.
Increase the request timeout for applications that intentionally accept slow uploads.

Excess connections receive a best-effort `503` with `Retry-After: 1` and are
immediately closed without allocating a worker fiber. A connection slot is released
on completion, timeout, disconnection or handler/write failure. This is a global
resource limit, not per-IP rate limiting; configure the latter at your ingress.

The listening socket uses a 511-deep backlog by default, adjustable through the
`backlog` constructor argument; the kernel caps it at `net.core.somaxconn`.
A larger backlog matters when many clients connect at the same moment: with a small
queue the kernel drops SYNs, and TCP retransmits them after about a second, which
shows up as multi-second response times in load tests.

The original request-size, Host, timeout and connection defaults were introduced in
**v3.0.4**. Existing constructor argument positions remain supported. The new parsing
options preserve the original server defaults unless explicitly configured. Valid
requests within the configured limits remain supported; clients sending invalid
HTTP/1.1 Host headers must be corrected.

### Memory budgets and automatic parsing

HTTP does not define a universal maximum request-body size. The body limit is a
deployment policy, not a guarantee about the memory needed to process that body.
In particular, `json_decode()` can turn a small JSON document containing many tiny
objects into a much larger PHP object graph. Multipart parsing also buffers and
allocates additional structures. A depth limit does not limit the number of elements.

Configure PHP's `memory_limit`, `maxBodyBytes`, `maxParsedBodyBytes` and
`maxConnections` together. `memory_limit` applies to the shared PHP process, not
separately to each fiber/request. Allow headroom for headers, raw bodies, parsing,
handlers, response buffers and outgoing HTTP calls. PHP memory-exhaustion failures
cannot reliably be caught by `catch (Throwable)` and can terminate the whole server.
The library never raises `memory_limit` or infers a supposedly safe body size from it.

For example, a small JSON service can start with explicit limits like these, then
measure and adjust them for its workload and process/container memory budget:

```php
$server = new HttpServer(
    '127.0.0.1', 8000, $factory, $factory, $factory, $factory, $factory,
    maxBodyBytes: 1048576,       // 1 MiB for raw request bodies
    maxConnections: 4,
    maxParsedBodyBytes: 65536,   // 64 KiB for automatic JSON/multipart parsing
);
```

These are illustrative values, not a guarantee of safety under a 128 MiB memory
limit. Test adversarial payloads and peak concurrency as well as normal requests.
The parsing limit is an additional cap on input bytes, **not** the memory size of
the parsed result. It never increases `maxBodyBytes`. Declared oversized bodies are
rejected before reading them, and the cap is also enforced while decoding chunked
input. Rejections return `413` without invoking the handler.

Use `parseBody: false` when an application needs its own bounded/streaming parser
or must accept larger JSON/multipart bodies. The raw PSR-7 body is still populated,
rewound and limited by `maxBodyBytes`; `getParsedBody()` and `getUploadedFiles()`
are not automatically populated. This does not itself make socket ingestion stream
directly into the handler or protect an application parser from excessive allocation.

### Client limits and timeouts

The client reads fixed-length, chunked, and connection-close-delimited responses.
Informational responses are skipped until the final response; HEAD, 204, 205,
and 304 responses do not wait for a body. Truncated bodies are errors, and chunk
payloads are preserved byte-for-byte. Protocol upgrades (`101`) and transfer
codings other than `chunked` are not supported.

The client accepts these additional named constructor arguments after its existing
factory, logger and connect-timeout arguments:

| Argument | Default | Behavior |
| --- | --- | --- |
| `maxResponseBodyBytes` | `8388608` (8 MiB) | Maximum decoded response-body bytes for fixed-length, chunked and connection-close framing. |
| `requestTimeout` | `30.0` seconds | Absolute deadline covering connection establishment, TLS, request writes, response headers, body and chunk trailers. |
| `maxResponseHeaderBytes` | `65536` (64 KiB) | Maximum bytes per response header section, including informational responses. |

```php
$client = new HttpClient(
    $factory, new StreamSocketFactory(),
    timeout: 3,
    maxResponseBodyBytes: 1048576,
    requestTimeout: 10.0,
);
```

The original `timeout` argument now bounds completion of the asynchronous TCP
connection **and** TLS handshake together. A value of `0` disables that separate
connect limit, but never the overall `requestTimeout`. Receiving/sending more bytes
does not reset the absolute deadlines; the existing five-second socket inactivity
timeout also remains. Timeouts must be finite and positive except for the legacy
zero connect timeout, and byte limits must be non-negative (header limits at least 2).

HTTPS connects over non-blocking TCP first, then negotiates TLS cooperatively on
the same event loop. Peer certificate, hostname verification and SNI remain enabled.
Application request bytes are not sent until TLS succeeds, and there is no plaintext
fallback. Schedule `sendRequest()` inside a Fiberloop fiber as in the usage example.

Response-size/header-limit failures use `ClientException` with the underlying
`LengthException`; network/connect/TLS/deadline failures use `NetworkException`.
Every outcome closes the connection. HEAD and bodyless statuses do not reject an
advertised representation size merely because it exceeds the body limit.

The new client defaults intentionally bound previously unbounded response reads.
Increase the size/time limits explicitly for large downloads or slow endpoints, and
include concurrent outgoing responses in the process memory budget. Deadlines do
not preempt blocking DNS resolution or synchronous application/PSR-stream code;
expired DNS lookups are rejected when control returns.

Client failures implement PSR-18's `ClientExceptionInterface`. Network failures
use `bronsted\NetworkException`, invalid or unreadable outgoing requests use
`bronsted\RequestException`, and invalid responses use `bronsted\ClientException`.
The original request and underlying exception are available through
`getRequest()` and `getPrevious()`. Outgoing request bodies must have a known
size; unknown-size bodies are rejected with a request exception rather than
sent without framing. Hostname resolution remains blocking.

On PHP 8.3 and newer, the server uses a bounded 1 ms non-blocking accept poll to
work around Fiberloop treating listening TCP sockets as EOF. Earlier runtimes use
readiness notifications. Pending socket reads are woken by Fiberloop's EOF
notification when a peer closes its send direction, so no polling is needed.

## Tests

GitHub Actions runs the full test suite on PHP 8.1 through 8.5 for pushes
(including tags), pull requests and manual runs. The workflow is defined in
`.github/workflows/tests.yml`.

Run `make test` to build the Docker image and run `composer install` and
`composer test` inside it, or `make coverage` for Xdebug coverage. If you have
PHP locally, `composer test` and `composer run coverage` work as well.
The suite includes byte-preservation, HTTP framing, parsing/response limits,
error-isolation, backpressure/deadline tests, and local TCP/TLS integration tests.
TLS tests generate disposable certificates and check trusted, untrusted, expired
and wrong-host certificates without requiring external HTTP services.

## Stress test

`run.dockerfile` builds an image that serves `example/server.php` on port 8000, and
`siege.dockerfile` builds a [Siege](https://www.joedog.org/siege-home/) client image.
`make stress` builds both, starts the server on a private Docker network, waits until
it accepts connections, runs Siege against it, and then removes the container and
network:

```sh
make stress                                # 250 concurrent clients for 60 s
make stress CONCURRENCY=50 DURATION=10S    # quick smoke test
make stress PHP_VERSION=8.1                # another PHP runtime
```

`SIEGE_ARGS` is passed through to Siege, for example `make stress SIEGE_ARGS=-k`.
Siege runs in benchmark mode (`-b`), and the target fails if Siege reports failed
transactions or the server does not start listening in time.

The images can also be used on their own:

```sh
docker build -f run.dockerfile   -t psr-http-run   .
docker build -f siege.dockerfile -t psr-http-siege .
docker run --rm -p 8000:8000 psr-http-run
docker run --rm --network host psr-http-siege -b -c 10 -t 10S http://127.0.0.1:8000/
```

## Bugs and improvements
Report bugs in GitHub issues or feel free to make a pull request :-)

## License
MIT
