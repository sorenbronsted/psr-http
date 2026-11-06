<?php

namespace bronsted;

use Psr\Http\Message\MessageInterface;
use UnexpectedValueException;

/**
 * Shared HTTP/1 message framing and bounded header parsing.
 * @internal
 */
final class HttpParser
{
    public static function readHeaders(StreamSocket $reader, MessageInterface $message, int $maxBytes = 65536): MessageInterface
    {
        $remaining = $maxBytes;
        while (true) {
            if ($remaining < 2) {
                throw new \LengthException('Headers too large', 431);
            }
            $line = $reader->readLine(min(8192, $remaining));
            $remaining -= strlen($line);
            if ($line === "\r\n") {
                return $message;
            }
            if ($line === '') {
                throw new SocketException('Unexpected EOF in headers');
            }
            if (!str_ends_with($line, "\r\n") || !str_contains($line, ':')) {
                throw new UnexpectedValueException('Malformed header', 400);
            }
            [$name, $value] = explode(':', substr($line, 0, -2), 2);
            // Whitespace before the colon and obsolete line folding are invalid.
            if ($name === '' || trim($name) !== $name) {
                throw new UnexpectedValueException('Malformed header name', 400);
            }
            $message = $message->withAddedHeader($name, trim($value, " \t"));
        }
    }

    public static function validateHost(MessageInterface $message): void
    {
        $values = $message->getHeader('Host');
        if (count($values) !== 1) {
            throw new UnexpectedValueException('Exactly one Host header is required', 400);
        }
        $host = $values[0];
        // A registered name or bracketed IP literal, optionally followed by a
        // port. Commas are rejected rather than interpreting a combined list.
        if (!preg_match("/^((?:[A-Za-z0-9._~!$&'()*+;=-]|%[A-Fa-f0-9]{2})+|\\[[^\\]]+\\])(?::([0-9]+))?$/D", $host, $parts)
            || (isset($parts[2]) && (int) $parts[2] > 65535)) {
            throw new UnexpectedValueException('Invalid Host header', 400);
        }
        if ($parts[1][0] === '[') {
            $literal = substr($parts[1], 1, -1);
            if (filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false
                && !preg_match("/^v[0-9a-f]+\\.[A-Za-z0-9._~!$&'()*+,;=:-]+$/iD", $literal)) {
                throw new UnexpectedValueException('Invalid Host IP literal', 400);
            }
        }
    }

    public static function contentLength(MessageInterface $message): ?int
    {
        if (!$message->hasHeader('Content-Length')) {
            return null;
        }
        $length = null;
        foreach (explode(',', $message->getHeaderLine('Content-Length')) as $value) {
            $value = trim($value);
            if (!preg_match('/^[0-9]+$/D', $value)) {
                throw new UnexpectedValueException('Invalid Content-Length', 400);
            }
            $normalized = ltrim($value, '0');
            $normalized = $normalized === '' ? '0' : $normalized;
            $maximum = (string) PHP_INT_MAX;
            if (strlen($normalized) > strlen($maximum)
                || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
                throw new \LengthException('Content-Length too large', 413);
            }
            $current = (int) $normalized;
            if ($length !== null && $length !== $current) {
                throw new UnexpectedValueException('Conflicting Content-Length values', 400);
            }
            $length = $current;
        }
        return $length;
    }

    public static function isChunked(MessageInterface $message): bool
    {
        if (!$message->hasHeader('Transfer-Encoding')) {
            return false;
        }
        if (strtolower(trim($message->getHeaderLine('Transfer-Encoding'))) !== 'chunked') {
            throw new UnexpectedValueException('Unsupported Transfer-Encoding', 400);
        }
        if ($message->hasHeader('Content-Length')) {
            throw new UnexpectedValueException('Ambiguous message framing', 400);
        }
        return true;
    }
}
