<?php

namespace bronsted;

use Closure;
use Exception;
use Kekos\MultipartFormDataParser\Parser;
use LengthException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

class HttpServer
{
    private string $interface;
    private int $port;
    private ServerRequestFactoryInterface $requestFactory;
    private ResponseFactoryInterface $responseFactory;
    private UploadedFileFactoryInterface $uploadFactory;
    private UriFactoryInterface $uriFactory;
    private StreamFactoryInterface $streamFactory;
    private FiberLoop $loop;
    private int $maxHeaderBytes;
    private int $maxBodyBytes;
    private LoggerInterface $log;

    public function __construct(
        string $interface,
        int $port,
        ServerRequestFactoryInterface $requestFactory,
        ResponseFactoryInterface $responseFactory,
        UploadedFileFactoryInterface $uploadFactory,
        UriFactoryInterface $uriFactory,
        StreamFactoryInterface $streamFactory,
        ?LoggerInterface $log = null,
        int $maxHeaderBytes = 65536,
        int $maxBodyBytes = 8388608
    ) {
        if ($maxHeaderBytes < 2 || $maxBodyBytes < 0) {
            throw new \InvalidArgumentException('Invalid HTTP size limits');
        }
        $this->interface = $interface;
        $this->port = $port;
        $this->requestFactory = $requestFactory;
        $this->responseFactory = $responseFactory;
        $this->uploadFactory = $uploadFactory;
        $this->uriFactory = $uriFactory;
        $this->streamFactory = $streamFactory;
        $this->log = $log ?? new NullLogger();
        $this->maxHeaderBytes = $maxHeaderBytes;
        $this->maxBodyBytes = $maxBodyBytes;
        $this->loop = FiberLoop::instance();
    }

    public function getFiberLoop(): FiberLoop
    {
        return $this->loop;
    }

    public function run(Closure $callback)
    {
        $server = @stream_socket_server('tcp://' . $this->interface . ':' . $this->port);
        if (!$server) {
            throw new Exception('Create socket failed');
        }
        stream_set_blocking($server, false);
        $accept = function() use ($server, $callback): bool {
            $stream = @stream_socket_accept($server, 0);
            if (!is_resource($stream)) {
                return false;
            }
            stream_set_blocking($stream, false);
            $stream = new StreamSocket($stream);
            $this->loop->defer(function() use ($stream, $callback) {
                $this->work($stream, $callback);
            });
            return true;
        };
        if (PHP_VERSION_ID >= 80300) {
            // PHP 8.3 and newer report EOF on listening TCP streams with queued
            // clients. Fiberloop drops such read watchers. Poll non-blocking
            // accept with a timer on these runtimes, rather than spinning or
            // using its internal scheduling API. Bound each batch to keep other
            // work fair.
            $this->loop->repeat(0.001, function() use ($server, $accept) {
                if (!is_resource($server)) {
                    return 0;
                }
                for ($i = 0; $i < 64 && $accept(); $i++) {
                }
                return null;
            });
        }
        else {
            $this->loop->onReadable($server, function() use ($accept) { $accept(); });
        }
        try {
            $this->loop->run();
        }
        finally {
            if (is_resource($server)) {
                fclose($server);
            }
        }
    }

    private function work(StreamSocket $stream, Closure $callback)
    {
        $start = microtime(true);
        $request = null;
        try {
            try {
                $request = $this->createRequest($stream);
                $request = $this->addHeaders($stream, $request);
                $request = $this->addBody($stream, $request);
                $response = null;
            }
            catch (Throwable $error) {
                $code = $error->getCode();
                if ($code < 400 || $code > 499) {
                    $code = $error instanceof Exception ? 400 : 500;
                }
                $response = $this->responseFactory->createResponse($code);
                $this->writeLog($code < 500 ? 'warning' : 'error', 'HttpServer invalid request {exception}', ['exception' => $error]);
            }

            if ($response === null) {
                try {
                    $response = $callback->call($this, $request);
                    if (!$response instanceof ResponseInterface) {
                        throw new \UnexpectedValueException('Handler must return a response');
                    }
                }
                catch (Throwable $error) {
                    $this->writeLog('error', 'HttpServer handler failed {exception}', ['exception' => $error]);
                    $response = $this->responseFactory->createResponse(500);
                }
            }
            $this->writeResponse($stream, $response, $request?->getMethod() === 'HEAD');
        }
        catch (Throwable $error) {
            $this->writeLog('error', 'HttpServer response failed {exception}', ['exception' => $error]);
            // A disconnected peer or broken response stream must not stop the
            // listener. A partially written response cannot safely be replaced.
        }
        finally {
            $stream->close();
            $this->writeLog('debug', 'HttpServer request time {time}', ['time' => microtime(true) - $start]);
        }
    }

    private function writeLog(string $level, string $message, array $context): void
    {
        try {
            $this->log->$level($message, $context);
        }
        catch (Throwable $error) {
            // Logging must not stop request processing or the shared event loop.
        }
    }

    private function writeResponse(StreamSocket $writer, ResponseInterface $response, bool $head = false)
    {
        $contentLength = $response->getBody()->getSize();
        $status = $response->getStatusCode();
        $noBody = $head || $status < 200 || in_array($status, [204, 205, 304], true);
        $response = $response->withHeader('Connection', 'close')->withoutHeader('Transfer-Encoding');
        if ($status < 200 || in_array($status, [204, 304], true)) {
            $response = $response->withoutHeader('Content-Length');
        }
        elseif ($status === 205) {
            $response = $response->withHeader('Content-Length', '0');
        }
        elseif ($contentLength !== null) {
            $response = $response->withHeader('Content-Length', (string) $contentLength);
        }
        else {
            $response = $response->withoutHeader('Content-Length');
        }

        $writer->write(sprintf("HTTP/%s %s %s\r\n", $response->getProtocolVersion(), $status, $response->getReasonPhrase()));
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $writer->write($name . ': ' . $value . "\r\n");
            }
        }
        $writer->write("\r\n");
        if (!$noBody) {
            $writer->copyFrom($response->getBody());
        }
    }

    private function createRequest(StreamSocket $reader): ServerRequestInterface
    {
        try {
            $line = $reader->readLine(8002);
        }
        catch (LengthException $error) {
            throw new LengthException('Request line too long', 414, $error);
        }
        if (!str_ends_with($line, "\r\n")) {
            throw new Exception('Malformed start line', 400);
        }
        $line = substr($line, 0, -2);
        if (strlen($line) > 8000) {
            throw new LengthException('Request line too long', 414);
        }
        $parts = explode(' ', $line);
        if (count($parts) !== 3) {
            throw new Exception('Malformed start line', 400);
        }
        [$method, $uri, $protocol] = $parts;
        if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'CONNECT', 'OPTIONS', 'TRACE'], true)) {
            throw new Exception('Wrong method', 400);
        }
        if ($protocol !== 'HTTP/1.1' || $uri === '') {
            throw new Exception('Invalid request target or protocol', 400);
        }
        $uri = $this->uriFactory->createUri($uri);
        $query = [];
        if ($uri->getQuery() !== '') {
            foreach (explode('&', $uri->getQuery()) as $part) {
                if ($part !== '') {
                    [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
                    $query[urldecode($name)] = urldecode($value);
                }
            }
        }
        return $this->requestFactory->createServerRequest($method, $uri)->withQueryParams($query);
    }

    private function addHeaders(StreamSocket $reader, ServerRequestInterface $request): ServerRequestInterface
    {
        $request = HttpParser::readHeaders($reader, $request, $this->maxHeaderBytes);
        $cookies = [];
        foreach ($request->getHeader('Cookie') as $header) {
            foreach (explode(';', $header) as $part) {
                if (!str_contains($part, '=')) {
                    continue;
                }
                [$name, $value] = explode('=', trim($part), 2);
                $name = trim($name);
                if ($name !== '' && !array_key_exists($name, $cookies)) {
                    $cookies[$name] = rawurldecode(trim($value, " \t\""));
                }
            }
        }
        return $request->withCookieParams($cookies);
    }

    private function addBody(StreamSocket $reader, ServerRequestInterface $request): ServerRequestInterface
    {
        $chunked = HttpParser::isChunked($request);
        $length = HttpParser::contentLength($request);
        $body = $request->getBody();
        if ($chunked) {
            $reader->copyChunkedTo($body, $this->maxBodyBytes);
        }
        elseif ($length !== null) {
            if ($length > $this->maxBodyBytes) {
                throw new LengthException('Body too large', 413);
            }
            $reader->copyTo($body, $length);
        }
        else {
            return $request;
        }
        $body->rewind();
        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));
        if ($type === 'multipart/form-data') {
            $parser = new Parser($body->getContents(), $request->getHeaderLine('Content-Type'), $this->uploadFactory, $this->streamFactory);
            $request = $parser->decorateRequest($request);
        }
        elseif ($type === 'application/json' || str_ends_with($type, '+json')) {
            $data = json_decode($body->getContents(), false, 512, JSON_THROW_ON_ERROR);
            $request = $request->withParsedBody(is_array($data) || is_object($data) ? $data : null);
        }
        $body->rewind();
        return $request;
    }
}
