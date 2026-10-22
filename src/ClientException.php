<?php

namespace bronsted;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

class ClientException extends RuntimeException implements ClientExceptionInterface
{
    public function __construct(private RequestInterface $request, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
