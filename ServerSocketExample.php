<?php

function memoryStatus()
{
    $memory = memory_get_usage() / 1024;
    $formatted = number_format($memory) . ' Kb';
    echo "Current memory usage: {$formatted}\n";
}

function readHttpLine($con): string
{
    $stop = false;
    $chars = '';
    while(!feof($con) && !$stop) {
        do {
            $byte = fread($con, 1);
            $chars .= $byte;
        } while($byte && $byte != "\n");
        $stop = (strlen($chars) > 1 && $chars[-1] == "\n");
    }
    return $chars;
}

function readHeaders($con)
{
    $header = trim(readHttpLine($con));
    while($header) {
        echo $header . PHP_EOL;
        $header = trim(readHttpLine($con));
    }
}

function run()
{
    $ipServer = '127.0.0.1';
    $portNumber = '8000';
    $errno = 0;
    $errstr = '';
    $nbSecondsIdle = 5* 60;

    $socket = stream_socket_server('tcp://' . $ipServer . ':' . $portNumber, $errno, $errstr);
    if (!$socket) {
        echo "$errstr ($errno)";
        return;
    }
    stream_set_blocking($socket, false);

    while (!feof($socket)) {
        memoryStatus();
        $con = @stream_socket_accept($socket, $nbSecondsIdle);
        stream_set_blocking($con, false);
        readHeaders($con);
        memoryStatus();
        fclose($con);
    }
    fclose($socket);
}

run();