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
use Psr\Http\Message\StreamFactoryInterface;
use Revolt\EventLoop;

class HttpServer
{
    private ServerRequestFactoryInterface $requestFactory;
    private ResponseFactoryInterface $responseFactory;
    private UploadedFileFactoryInterface $uploadFactory;
    private UriFactoryInterface $uriFactory;
    private StreamFactoryInterface $streamFactory;

    public function __construct(
        ServerRequestFactoryInterface $requestFactory,
        ResponseFactoryInterface $responseFactory,
        UploadedFileFactoryInterface $uploadFactory,
        UriFactoryInterface $uriFactory,
        StreamFactoryInterface $streamFactory
    ) {
        $this->requestFactory = $requestFactory;
        $this->responseFactory = $responseFactory;
        $this->uploadFactory = $uploadFactory;
        $this->uriFactory = $uriFactory;
        $this->streamFactory = $streamFactory;
    }

    public function run(Closure $callback)
    {
        $server = stream_socket_server('tcp://0.0.0.0:8080');
        if (!$server) {
            exit(1);
        }
        stream_set_blocking($server, false);

        EventLoop::onReadable($server, function ($watcher, $server) use ($callback) {
            $connection = \stream_socket_accept($server, 5);
            if (!$connection) {
                return;
            }
            // read from connection
            $response = null;
            try {
                $request = $this->createRequest($connection);
                $request = $this->addHeaders($connection, $request);
                $request = $this->addBody($connection, $request);
            } catch (Exception $e) {
                $code = $e->getCode();
                if ($code < 400 || $code > 499) {
                    $code = 400;
                }
                $response = $this->responseFactory->createResponse($code);
            }

            // call callback
            if (empty($response)) {
                $response = $callback->call($this, $request);
            }

            // send response
            EventLoop::onWritable($connection, function ($watcher, $connection) use ($response) {
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
                fwrite($connection, $line . $crlf);

                foreach ($response->getHeaders() as $name => $value) {
                    $line = sprintf('%s: %s', $name, is_array($value) ? implode(';', $value) : $value);
                    fwrite($connection, $line . $crlf);
                }
                fwrite($connection, $crlf);

                if ($contentLength > 0) {
                    $body = $response->getBody();
                    $body->rewind();
                    fwrite($connection, $body->read($contentLength), $contentLength);
                }
                fclose($connection);
                EventLoop::cancel($watcher);
            });
        });

        EventLoop::run();
    }

    private function createRequest($connection): ServerRequestInterface
    {
        $line = fgets($connection);
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
        if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'CONNECT', 'OPTIONS', 'TRACE'])) {
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

    private function addHeaders($connection, ServerRequestInterface $request): ServerRequestInterface
    {
        $line = trim(fgets($connection));
        $cookies = [];
        while ($line) {
            $parts = explode(':', $line);
            $name = trim($parts[0]);
            $value = trim($parts[1]) ?? '';
            if (strtolower($name) == 'cookie') {
                $parts = explode(';', $value);
                foreach ($parts as $part) {
                    $subParts = explode(':', $part);
                    $cookies[$subParts[0]] = $subParts[1] ?? '';
                }
            } else {
                $request = $request->withAddedHeader($name, $value);
            }
            $line = trim(fgets($connection));
        }
        return $request->withCookieParams($cookies);
    }

    private function addBody($connection, ServerRequestInterface $request): ServerRequestInterface
    {
        $length = $request->getHeader('content-length');
        $type = $request->getHeader('content-type');
        if (empty($length) || empty($type)) {
            return $request;
        }
        $length = $length[0];
        $body = $request->getBody();
        $body->write(fread($connection, $length));
        $body->rewind();

        $type = $type[0];
        if (strpos($type, 'form-data') > 0) {
            $parser = new Parser((string)$request->getBody(), $type, $this->uploadFactory, $this->streamFactory);
            $parser->decorateRequest($request);
        } else if (strpos($type, 'json') > 0) {
            $data = json_decode($body->read($length));
            $request = $request->withParsedBody($data);
        }
        return $request;
    }
}
