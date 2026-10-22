<?php

namespace bronsted;

use Psr\Http\Client\RequestExceptionInterface;

class RequestException extends ClientException implements RequestExceptionInterface
{
}
