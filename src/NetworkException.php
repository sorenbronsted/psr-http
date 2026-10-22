<?php

namespace bronsted;

use Psr\Http\Client\NetworkExceptionInterface;

class NetworkException extends ClientException implements NetworkExceptionInterface
{
}
