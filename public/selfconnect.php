<?php


$errcode = 0;
$errstr = '';
$server = stream_socket_server('tcp://0.0.0.0:8000', $errcode, $errstr);
if (!$server) {
    echo "Server $errstr ($errcode)\n";
}
stream_set_blocking($server, false);
while ($con = stream_socket_accept($server)) {
    $client = stream_socket_client('tcp://127.0.0.1:8000', $errcode, $errstr);
    if (!$client) {
        echo "Client $errstr ($errcode)\n";
    }
    stream_set_blocking($client, false);
    fclose($client);

    fclose($con);
}
fclose($server);
