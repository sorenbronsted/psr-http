<?php

namespace bronsted;

class StreamSocketFactory
{
    public function createStreamSocket(mixed $stream): StreamSocket
    {
        return new StreamSocket($stream);
    }
}