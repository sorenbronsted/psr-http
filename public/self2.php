<?php
require __DIR__ . '/../vendor/autoload.php';

use bronsted\Scheduler;
use bronsted\StreamReader;
use bronsted\StreamWriter;

function run() {
    $server = stream_socket_server('tcp://0.0.0.0:8000');
    if (!$server) {
        throw new Exception("Server failed");
    }
    stream_set_blocking($server, false);

    $n = 0;
    Scheduler::instance()->onReadable($server, function($server) use(&$n) {
        $n++;
        $con = stream_socket_accept($server);
        stream_set_blocking($con, false);
        echo "Connected $n\n";
        $reader = new StreamReader($con);
        echo $reader->readLine();
        $reader->close();

        if ($n >= 2) {
            return;
        }
        Scheduler::instance()->defer(function() {
            $client = stream_socket_client('tcp://127.0.0.1:8000');
            if (!$client) {
                throw new Exception("Client failed");
            }
            stream_set_blocking($client, false);
            Scheduler::instance()->onWriteable($client, function ($client) {
                $writer = new StreamWriter($client);
                $writer->write('Hi');
                $writer->close();
            });
        });
    });

    Scheduler::instance()->run();
}

run();