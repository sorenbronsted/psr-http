<?php

namespace bronsted;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use UnexpectedValueException;

class HttpClient implements ClientInterface
{
    private ResponseFactoryInterface $responseFactory;
    private StreamSocketFactory $streamSocketFactory;
    private LoggerInterface $log;
    private int $timeout;

    public function __construct(
        ResponseFactoryInterface $responseFactory,
        StreamSocketFactory $stream,
        ?LoggerInterface $log = null,
        int $timeout = 5
    ) {
        if ($timeout < 0) {
            throw new \InvalidArgumentException('Connect timeout must be non-negative');
        }
        $this->responseFactory = $responseFactory;
        $this->streamSocketFactory = $stream;
        $this->log = $log ?? new NullLogger();
        $this->timeout = $timeout;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $original = $request;
        $start = microtime(true);
        $stream = $connection = null;
        try {
            try {
                $uri = $request->getUri();
                if (!in_array(strtolower($uri->getScheme()), ['http', 'https'], true) || $uri->getHost() === '') {
                    throw new \InvalidArgumentException('An absolute HTTP or HTTPS URI is required');
                }
                $contentLength = $request->getBody()->getSize();
                if ($contentLength === null || $contentLength < 0) {
                    throw new \InvalidArgumentException('Request body size must be known');
                }
                $request = $request->withHeader('Connection', 'close')->withoutHeader('Transfer-Encoding');
                if ($contentLength > 0 || $request->hasHeader('Content-Length')) {
                    $request = $request->withHeader('Content-Length', (string) $contentLength);
                }
            }
            catch (Throwable $error) {
                throw new RequestException($original, $error->getMessage(), $error);
            }

            $connection = $this->connect($request->getUri());
            $stream = $this->streamSocketFactory->createStreamSocket($connection);
            try {
                $this->writeRequest($stream, $request);
            }
            catch (SocketException $error) {
                throw $error;
            }
            catch (Throwable $error) {
                throw new RequestException($original, $error->getMessage(), $error);
            }
            $response = $this->readReponse($stream, $request->getMethod());
            $this->writeLog('debug', 'HttpClient request {uri} time {time} ', [
                'uri' => $original->getUri(),
                'time' => microtime(true) - $start,
            ]);
            return $response;
        }
        catch (Throwable $error) {
            if (!$error instanceof ClientExceptionInterface) {
                $error = $error instanceof SocketException
                    ? new NetworkException($original, $error->getMessage(), $error)
                    : new ClientException($original, $error->getMessage(), $error);
            }
            $this->writeLog('error', 'Failed {uri} {exception}', ['uri' => $original->getUri(), 'exception' => $error]);
            throw $error;
        }
        finally {
            if ($stream !== null) {
                try {
                    $stream->close();
                }
                catch (Throwable $error) {
                    // Cleanup must not replace the original request failure.
                }
            }
            if (is_resource($connection)) {
                fclose($connection);
            }
        }
    }

    private function writeLog(string $level, string $message, array $context): void
    {
        try {
            $this->log->$level($message, $context);
        }
        catch (Throwable $error) {
            // A logging backend must not change the HTTP result or mask its error.
        }
    }

    private function connect(UriInterface $uri)
    {
        // DNS resolution remains blocking; literal IPv4/IPv6 addresses need no lookup.
        $host = trim($uri->getHost(), '[]');
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new SocketException('Unable to resolve hostname');
        }
        $scheme = strtolower($uri->getScheme());
        $context = null;
        if ($scheme === 'https') {
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'SNI_enabled' => true,
                    'peer_name' => $host,
                ],
            ]);
        }
        $address = ($scheme === 'http' ? 'tcp' : 'ssl') . '://' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip);
        $address .= ':' . ($uri->getPort() ?? ($scheme === 'http' ? 80 : 443));
        $errno = 0;
        $errstr = '';
        $connection = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            $context
        );
        if (!$connection) {
            throw new SocketException('Unable to connect: ' . $errstr, $errno);
        }
        stream_set_blocking($connection, false);
        return $connection;
    }

    private function writeRequest(StreamSocket $writer, RequestInterface $request)
    {
        $uri = $request->getUri();
        $url = $uri->getPath();
        if ($url === '' || $url[0] !== '/') {
            $url = '/' . $url;
        }
        if ($uri->getQuery() !== '') {
            $url .= '?' . $uri->getQuery();
        }
        $writer->write(sprintf("%s %s HTTP/%s\r\n", $request->getMethod(), $url, $request->getProtocolVersion()));
        $host = $uri->getHost();
        if ($uri->getPort() !== null) {
            $host .= ':' . $uri->getPort();
        }
        $request = $request->withHeader('Host', $host);
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $writer->write($name . ': ' . $value . "\r\n");
            }
        }
        $writer->write("\r\n");
        $writer->copyFrom($request->getBody());
    }

    private function readReponse(StreamSocket $reader, string $method = 'GET'): ResponseInterface
    {
        for ($interim = 0; ; $interim++) {
            $response = $this->responseFactory->createResponse();
            $response = $this->addResponseStartLine($reader, $response);
            $response = HttpParser::readHeaders($reader, $response);
            $status = $response->getStatusCode();
            if ($status >= 200) {
                break;
            }
            if ($status === 101 || $interim >= 15) {
                throw new UnexpectedValueException('Unsupported or excessive informational responses');
            }
        }
        if (strtoupper($method) === 'HEAD' || in_array($status, [204, 205, 304], true)) {
            return $response;
        }
        return $this->addBody($reader, $response);
    }

    private function addResponseStartLine(StreamSocket $reader, ResponseInterface $response): ResponseInterface
    {
        $line = $reader->readLine(8002);
        if ($line === '') {
            throw new SocketException('Connection closed before response');
        }
        if (!preg_match('/^HTTP\/(1(?:\.[01])?) ([1-5][0-9]{2})(?: ([^\r\n]*))?\r\n$/D', $line, $parts)) {
            throw new UnexpectedValueException('Malformed response start line');
        }
        return $response->withProtocolVersion($parts[1])->withStatus((int) $parts[2], $parts[3] ?? '');
    }

    private function addBody(StreamSocket $reader, ResponseInterface $message): ResponseInterface
    {
        $body = $message->getBody();
        $chunked = HttpParser::isChunked($message);
        $length = HttpParser::contentLength($message);
        if ($chunked) {
            $reader->copyChunkedTo($body);
            $message = $message->withoutHeader('Transfer-Encoding')->withoutHeader('Trailer');
            if ($body->getSize() !== null) {
                $message = $message->withHeader('Content-Length', (string) $body->getSize());
            }
        }
        elseif ($length !== null) {
            $reader->copyTo($body, $length);
        }
        else {
            $reader->copyUntilEof($body);
        }
        $body->rewind();
        return $message;
    }
}
