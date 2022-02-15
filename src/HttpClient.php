<?php

namespace bronsted;

use Exception;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use SplTempFileObject;

require __DIR__ . '/../vendor/autoload.php';

class HttpClient
{
    private ResponseFactoryInterface $responseFactory;
//    private Suspension $suspension;

    public function __construct(ResponseFactoryInterface $responseFactory)
    {
        $this->responseFactory = $responseFactory;
    }

    function fetch(RequestInterface $request): ResponseInterface
    {
        $stream = $this->connect($request->getUri());

        // write request to buffer
        $request = $request->withHeader('Connection', 'close');
        $buffer = $this->writeRequest($request);

        // write buffe async to remote
        $this->writeStream($buffer, $stream);

        // read response to buffer
        $buffer = $this->readStream($stream);
        fclose($stream);

        // parse the buffer
        return $this->readReponse($buffer);
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
        $connection = stream_socket_client(
            $address,
            $errno,
            $errstr,
            PHP_INT_MAX, // not used because this a non blocking stream
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT, 
            $context
        );
        if (!$connection) {
            throw new Exception('Unable to connect', $errno);
        }
        stream_set_blocking($connection, false);
        return $connection;
    }

    private function writeStream(SplTempFileObject $buffer, $stream)
    {
        // write async to stream
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

    private function readStream($stream): SplTempFileObject
    {
        $buffer = new SplTempFileObject();
        $suspension = EventLoop::getSuspension();
        $watcher = EventLoop::onReadable($stream, fn () => $suspension->resume());
        while(is_resource($stream) && !feof($stream)) {
            $suspension->suspend();
            $buffer->fwrite(fread($stream, 64 * 1024));
        }
        EventLoop::cancel($watcher);
        return $buffer;
    }

    private function writeRequest(RequestInterface $request): SplTempFileObject
    {
        $buffer = new SplTempFileObject();

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

        $buffer->fwrite($startLine, strlen($startLine));

        $request = $request->withHeader('Host', $uri->getHost());
        foreach ($request->getHeaders() as $name => $values) {
            $line = $name . ': ' . implode(", ", $values) . $crlf;
            $buffer->fwrite($line, strlen($line));
        }
        $buffer->fwrite($crlf, strlen($crlf));

        $body = $request->getBody();
        if ($body->getSize() > 0) {
            $body->rewind();
            $buffer->fwrite($body()->getContents(), $body()->getSize());
        }
        return $buffer;
    }

    private function readReponse(SplTempFileObject $buffer): ResponseInterface
    {
        $bufferSize = $buffer->ftell();
        $buffer->rewind();
        $response = $this->responseFactory->createResponse();
        $response = $this->addResponseStartLine($buffer, $response);
        $response = $this->addHeaders($buffer, $response);
        $response = $this->addBody($buffer, $bufferSize, $response);
        return $response;
    }

    private function addResponseStartLine(SplTempFileObject $buffer, $response): ResponseInterface
    {
        $line = $buffer->fgets();
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

    private function addHeaders(SplTempFileObject $buffer, MessageInterface $message): MessageInterface
    {
        $line = trim($buffer->fgets());
        while ($line) {
            $parts = explode(':', $line, 2);
            $name = trim($parts[0]);
            $value = trim($parts[1]);
            $message = $message->withAddedHeader($name, $value);
            $line = trim($buffer->fgets());
        }
        return $message;
    }

    private function addBody(SplTempFileObject $buffer, int $bufferSize, MessageInterface $message): MessageInterface
    {
        // Try to figure out the length og the body
        $length = $message->getHeader('content-length');
        if (empty($length)) {
            // No length from header, so we look at the buffer
            $length = $buffer->eof() ? 0 : $bufferSize - $buffer->ftell();
        }
        else {
            // Header is always an array
            $length = $length[0];
        }

        // no length => no body
        if ($length <= 0) {
            return $message;
        }

        $body = $message->getBody();
        $body->write(trim($buffer->fread($length)));
        $body->rewind();
        return $message;
    }
}
