<?php

namespace bronsted;

use Exception;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriInterface;
use Throwable;
use function strpos;

class HttpClient implements ClientInterface
{
    private ResponseFactoryInterface $responseFactory;
    private StreamSocketFactory $streamSocketFactory;

    public function __construct(ResponseFactoryInterface $responseFactory, StreamSocketFactory $stream)
    {
        $this->responseFactory = $responseFactory;
        $this->streamSocketFactory = $stream;
    }

    function sendRequest(RequestInterface $request): ResponseInterface
    {
        $stream = null;
        try {
            $stream = $this->streamSocketFactory->createStreamSocket($this->connect($request->getUri()));

            $request = $request->withHeader('Connection', 'close');

            $contentLength = $request->getBody()->getSize();
            if ($contentLength > 0) {
                $request = $request->withAddedHeader('Content-Length', $contentLength);
            }

            $this->writeRequest($stream, $request);

            $response = $this->readReponse($stream);
            $stream->close();
            return $response;
        }
        catch (Throwable $th) {
            $stream?->close();
            throw $th;
        }
    }

    private function connect(UriInterface $uri)
    {
        // resolve hostname before establishing TCP/IP connection (resolving DNS is still blocking here)
        $ip = gethostbyname($uri->getHost());
        if (ip2long($ip) === false) {
            throw new Exception('Unable to resolve hostname');
        }

        $context = null;
        $scheme = strtolower($uri->getScheme());
        if ($scheme == 'https') {
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'SNI_enabled' => true,
                    'peer_name' => $uri->getHost()
                ]
            ]);
        }
        $address = ($scheme == 'http' ? 'tcp' : 'ssl') . '://' . $ip;
        $address = $address . ':' . ($uri->getPort() ? $uri->getPort() : ($scheme == 'http' ? '80' : '443'));
        // establish TCP/IP connection (non-blocking)
        $errno = 0;
        $errstr = '';
        $connection = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            0.5,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            $context
        );
        if (!$connection) {
            throw new Exception('Unable to connect', $errno);
        }
        stream_set_blocking($connection, false);
        return $connection;
    }

    private function writeRequest(StreamSocket $writer, RequestInterface $request)
    {
        $crlf = "\r\n";

        $uri = $request->getUri();
        $url = $uri->getPath();
        if (empty($url) || $url[0] != '/') {
            $url = $url . '/';
        }
        if ($uri->getQuery()) {
            $url = $url . '?' . $uri->getQuery();
        }
        $startLine = sprintf("%s %s HTTP/%s", 
                strtoupper($request->getMethod()),
                $url,
                $request->getProtocolVersion()
            ) . $crlf;

        $writer->write($startLine);

        $request = $request->withHeader('Host', $uri->getHost());
        foreach ($request->getHeaders() as $name => $values) {
            $line = $name . ': ' . implode(", ", $values) . $crlf;
            $writer->write($line);
        }
        $writer->write($crlf);
        $writer->copyFrom($request->getBody());
    }

    private function readReponse(StreamSocket $reader): ResponseInterface
    {
        $response = $this->responseFactory->createResponse();
        $response = $this->addResponseStartLine($reader, $response);
        $response = $this->addHeaders($reader, $response);
        return $this->addBody($reader, $response);
    }

    private function addResponseStartLine(StreamSocket $reader, $response): ResponseInterface
    {
        $line = $reader->readLine();
        if (empty($line)) {
            throw new Exception('Empty start line', 400);
        }
        $line = trim($line);
        if (strlen($line) > 8000) {
            throw new Exception('Line to long', 414);
        }
        $parts = explode(' ', $line, 3);
        if (empty($parts) || count($parts) < 2) {
            throw new Exception('Malformed start line', 400);
        }
        list($protocol, $statusCode) = $parts;
        $parts = explode('/', $protocol);
        if (count($parts) != 2) {
            throw new Exception('Malformed start line', 400);
        }
        if ($parts[0] != 'HTTP') {
            throw new Exception('Wrong protocol', 400);
        }
        return $response->withProtocolVersion($parts[1])->withStatus($statusCode);
    }

    private function addHeaders(StreamSocket $reader, ResponseInterface $message): ResponseInterface
    {
        $line = trim($reader->readLine());
        while ($line) {
            $parts = explode(':', $line, 2);
            $name = trim($parts[0]);
            $value = trim($parts[1]);
            $message = $message->withAddedHeader($name, $value);
            $line = trim($reader->readLine());
        }
        return $message;
    }

    private function addBody(StreamSocket $reader, ResponseInterface $message): ResponseInterface
    {
        $body = $message->getBody();
        $length = $message->getHeader('content-length');
        if (empty($length)) {
            $encoding = $message->getHeader('transfer-encoding');
            if (empty($encoding) || strpos($encoding[0], 'chunked') === false) {
                throw new Exception('Unknown body type', 400);
            }
            $reader->copyChunkedTo($body);
        }
        else {
            $length = $length[0];
            if ($length <= 0) {
                return $message;
            }
            $reader->copyTo($body, $length);
        }
        $body->rewind();
        return $message;
    }
}
