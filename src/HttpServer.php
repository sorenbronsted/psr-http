<?php

namespace bronsted;

use Closure;
use Exception;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Kekos\MultipartFormDataParser\Parser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use function strlen;
use function urldecode;

class HttpServer
{
    private string $interface;
    private string $port;
    private ServerRequestFactoryInterface $requestFactory;
    private ResponseFactoryInterface $responseFactory;
    private UploadedFileFactoryInterface $uploadFactory;
    private UriFactoryInterface $uriFactory;
    private StreamFactoryInterface $streamFactory;
    private FiberLoop $loop;

    public function __construct(
        string                        $interface,
        int                           $port,
        ServerRequestFactoryInterface $requestFactory,
        ResponseFactoryInterface      $responseFactory,
        UploadedFileFactoryInterface  $uploadFactory,
        UriFactoryInterface           $uriFactory,
        StreamFactoryInterface        $streamFactory
    )
    {
        $this->interface = $interface;
        $this->port = $port;
        $this->requestFactory = $requestFactory;
        $this->responseFactory = $responseFactory;
        $this->uploadFactory = $uploadFactory;
        $this->uriFactory = $uriFactory;
        $this->streamFactory = $streamFactory;
        $this->loop = FiberLoop::instance();
    }

    public function run(Closure $callback)
    {
        $server = stream_socket_server('tcp://' . $this->interface . ':' . $this->port);
        if (!$server) {
            throw new Exception("Create socket failed");
        }
        stream_set_blocking($server, false);

        $this->loop->onReadable($server, function ($server) use ($callback) {
            $stream = stream_socket_accept($server, 5);
            if (!$stream || !is_resource($stream)) {
                return;
            }
            stream_set_blocking($stream, false);
            $stream = new Stream($stream);

            $this->loop->defer(function() use($stream, $callback) {
                $this->work($stream, $callback);
            });
        });

        $this->loop->run();
    }

    private function work(Stream $stream, Closure $callback)
    {
        $request = $response = null;
        try {
            // Read headers from stream
            $request = $this->createRequest($stream);
            $request = $this->addHeaders($stream, $request);
            $request = $this->addBody($stream, $request);
        } catch (Exception $e) {
            $code = $e->getCode();
            if ($code < 400 || $code > 499) {
                $code = 400;
            }
            $response = $this->responseFactory->createResponse($code);
        }

        // call callback if we don't have a response already
        if (empty($response)) {
            $response = $callback->call($this, $request);
        }

        // response to buffer
        $this->writeResponse($stream, $response);
        $stream->close();
    }

    private function writeResponse(Stream $writer, ResponseInterface $response)
    {
        $crlf = "\r\n";

        $contentLength = $response->getBody()->getSize();
        if ($contentLength > 0) {
            $response = $response->withAddedHeader('Connection', 'close')
                ->withAddedHeader('Content-Length', $contentLength);
        }

        // write response
        $line = sprintf(
            'HTTP/%s %s %s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase()
        );
        $writer->write($line . $crlf);

        foreach ($response->getHeaders() as $name => $value) {
            $line = sprintf('%s: %s', $name, implode(';', $value));
            $writer->write($line . $crlf);
        }
        $writer->write($crlf);

        if ($contentLength > 0) {
            $body = $response->getBody();
            $body->rewind();
            $writer->copyFrom($body);
        }
    }

    private function createRequest(Stream $reader): ServerRequestInterface
    {
        $line = $reader->readLine();
        if (!$line) {
            throw new Exception('No start line', 400);
        }
        $line = trim($line);
        if (strlen($line) > 8000) {
            throw new Exception('Line to long', 414);
        }
        $parts = explode(' ', $line);
        if (empty($parts) || count($parts) != 3) {
            throw new Exception('Malformed start line', 400);
        }
        list($method, $uri, $protocol) = $parts;
        if (!in_array(strtoupper($method), ['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'CONNECT', 'OPTIONS', 'TRACE'])) {
            throw new Exception('Wrong method', 400);
        }
        if ($protocol != 'HTTP/1.1') {
            throw new Exception('Wrong protocol', 400);
        }
        if (empty($uri)) {
            throw new Exception('Empty uri', 400);
        }

        $uri = $this->uriFactory->createUri($uri);
        $queryStr = $uri->getQuery();
        $query = [];
        if ($queryStr) {
            $queryStr = urldecode($queryStr);
            $parts = explode('&', $queryStr);
            foreach ($parts as $part) {
                $subParts = explode('=', $part);
                $query[$subParts[0]] = $subParts[1] ?? '';
            }
        }
        return $this->requestFactory->createServerRequest($method, $uri)->withQueryParams($query);
    }

    private function addHeaders(Stream $reader, ServerRequestInterface $request): ServerRequestInterface
    {
        $line = trim($reader->readLine());
        $cookies = [];
        while ($line) {
            $parts = explode(':', $line, 2);
            $name = trim($parts[0]);
            $value = trim($parts[1]) ?? '';
            if (strtolower($name) == 'cookie') {
                $parts = explode('=', $value);
                foreach ($parts as $part) {
                    $subParts = explode(':', $part);
                    $cookies[$subParts[0]] = urldecode($subParts[1]) ?? '';
                }
            } else {
                $request = $request->withAddedHeader($name, $value);
            }
            $line = trim($reader->readLine());
        }
        return $request->withCookieParams($cookies);
    }

    private function addBody(Stream $reader, ServerRequestInterface $request): ServerRequestInterface
    {
        $length = $request->getHeader('content-length');
        $type = $request->getHeader('content-type');
        if (empty($length) || empty($type)) {
            return $request;
        }
        $length = $length[0];
        $body = $request->getBody();
        $reader->copyTo($body, $length);
        $body->rewind();

        $type = $type[0];
        if (strpos($type, 'form-data') > 0) {
            $parser = new Parser($request->getBody()->getContents(), $type, $this->uploadFactory, $this->streamFactory);
            $request = $parser->decorateRequest($request);
        } else if (strpos($type, 'json') > 0) {
            $data = json_decode($body->read($length));
            $request = $request->withParsedBody($data);
        }
        return $request;
    }
}
