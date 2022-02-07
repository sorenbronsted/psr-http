<?php

$ipServer = '127.0.0.1';
$portNumber = '8080';
$errno = 0;
$errstr = '';
$nbSecondsIdle = 5* 60;

$socket = stream_socket_server('tcp://' . $ipServer . ':' . $portNumber, $errno, $errstr);
if (!$socket) {
    echo "$errstr ($errno)";
    return;
}

$con = @stream_socket_accept($socket, $nbSecondsIdle);
$message = fread($con, 8 * 1024);
while ($message) {
    //file_put_contents('dump', $message);
    var_dump($message);
    //fwrite($con, "HTTP/1.1 200 OK\r\n");
    $message = fread($con, 8 * 1024);
}
fclose($con);
fclose($socket);
