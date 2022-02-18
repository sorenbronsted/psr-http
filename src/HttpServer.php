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
use Psr\Http\Message\StreamInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use SplTempFileObject;
use function ftruncate;
use function fwrite;

class HttpServer
{
    private string $interface;
    private string $port;
    private ServerRequestFactoryInterface $requestFactory;
    private ResponseFactoryInterface $responseFactory;
    private UploadedFileFactoryInterface $uploadFactory;
    private UriFactoryInterface $uriFactory;
    private StreamFactoryInterface $streamFactory;

    public function __construct(
        string $interface,
        int $port,
        ServerRequestFactoryInterface $requestFactory,
        ResponseFactoryInterface $responseFactory,
        UploadedFileFactoryInterface $uploadFactory,
        UriFactoryInterface $uriFactory,
        StreamFactoryInterface $streamFactory
    ) {
        $this->interface = $interface;
        $this->port = $port;
        $this->requestFactory = $requestFactory;
        $this->responseFactory = $responseFactory;
        $this->uploadFactory = $uploadFactory;
        $this->uriFactory = $uriFactory;
        $this->streamFactory = $streamFactory;
    }

    public function run(Closure $callback)
    {
        $server = stream_socket_server('tcp://' . $this->interface . ':' . $this->port);
        if (!$server) {
            exit(1);
        }
        stream_set_blocking($server, false);

        EventLoop::onReadable($server, function (string $id, $server) use ($callback) {
           
            $stream = stream_socket_accept($server, 5);
            if (!$stream || !is_resource($stream)) {
                return;
            }
            stream_set_blocking($stream, false);

            // Read headers from stream
            $buffer = $this->streamFactory->createStream();
            $this->readHeaders($stream, $buffer);
            $buffer->close();
            $buffer->detach();

            /* Parse the headers
            $response = null;
            try {
                $request = $this->createRequest($buffer);
                $request = $this->addHeaders($buffer, $request);
                $request = $this->addBody($stream, $request);
            } catch (Exception $e) {
                $code = $e->getCode();
                if ($code < 400 || $code > 499) {
                    $code = 400;
                }
                $response = $this->responseFactory->createResponse($code);
            }

            // call callback if we don't have a response allready
            if (empty($response)) {
                $response = $callback->call($this, $request);
            }

            // response to buffer
            $buffer = $this->writeReponse($response);

            // write buffer to client
            $this->writeStream($stream, $buffer);
            */
            fclose($stream);
        });

        EventLoop::run();
    }

    private function readHeaders($stream, StreamInterface $buffer)
    {
        $stop = false;
        while(!$stop) {
            $line = $this->readLine($stream);
            // end-of-headers is one line with \r\n
            $stop = (strlen($line) == 2 && $line[0] == "\r" && $line[1] == "\n");
            $buffer->write($line);
        }
    }

    private function readLine($stream): string
    {
        $stop = false;
        $chars = '';
        $suspension = EventLoop::getSuspension();
        $watcher = EventLoop::onReadable($stream, fn () => $suspension->resume());
        while(!feof($stream) && !$stop) {
            $byte = null;
            do {
                $byte = fread($stream, 1);
                $chars .= $byte;
            } while($byte && $byte != "\n");
            $stop = (strlen($chars) > 1 && $chars[-1] == "\n");
            if (!$stop) {
                $suspension->suspend();
            }
        }
        EventLoop::cancel($watcher);
        return $chars;
    }

    private function copyToStream($stream, $length, StreamInterface $destination)
    {
        $pos = 0;
        $suspension = EventLoop::getSuspension();
        $watcher = EventLoop::onReadable($stream, fn () => $suspension->resume());
        while(!feof($stream) && $pos < $length) {
            $suspension->suspend();
            $destination->write(fread($stream, $length - $pos));
            $pos = $destination->tell();
        }
        EventLoop::cancel($watcher);
    }
    
    private function writeStream($stream, SplTempFileObject $buffer)
    {
        $suspension = EventLoop::getSuspension();
        $watcher = EventLoop::onWritable($stream, fn () => $suspension->resume());
        $length = $buffer->ftell();
        $buffer->rewind();
        $pos = 0;
        do {
            $suspension->suspend();
            $buffer->fseek($pos);
            $written = fwrite($stream, $buffer->fread($length - $pos));
            $pos += $written;
        } while ($pos < $length);
        EventLoop::cancel($watcher);
    }

    private function writeReponse(ResponseInterface $response): SplTempFileObject
    {
        $buffer = new SplTempFileObject();
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
        $buffer->fwrite($line . $crlf);

        foreach ($response->getHeaders() as $name => $value) {
            $line = sprintf('%s: %s', $name, implode(';', $value));
            $buffer->fwrite($line . $crlf);
        }
        $buffer->fwrite($crlf);

        if ($contentLength > 0) {
            $body = $response->getBody();
            $body->rewind();
            $buffer->fwrite($body->read($contentLength), $contentLength);
        }
        return $buffer;
    }

    private function createRequest(SplTempFileObject $buffer): ServerRequestInterface
    {
        $buffer->rewind();
        $line = $buffer->fgets();
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

    private function addHeaders(SplTempFileObject $buffer, ServerRequestInterface $request): ServerRequestInterface
    {
        $line = trim($buffer->fgets());
        $cookies = [];
        while ($line) {
            $parts = explode(':', $line, 2);
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
            $line = trim($buffer->fgets());
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
        $this->copyToStream($connection, $length, $body);
        $body->rewind();

        $type = $type[0];
        if (strpos($type, 'form-data') > 0) {
            $parser = new Parser((string)$request->getBody(), $type, $this->uploadFactory, $this->streamFactory);
            $request = $parser->decorateRequest($request);
        } else if (strpos($type, 'json') > 0) {
            $data = json_decode($body->read($length));
            $request = $request->withParsedBody($data);
        }
        return $request;
    }
}
