<?php
// Open many connections at once and report how many were still not connected
// when the deadline passed. A full listen queue drops SYNs, and TCP retransmits
// them after about a second, so delayed connections point at a small backlog.
//
// Run in its own process: Brain Monkey patches stream_socket_client() in the
// test process, which breaks its by-reference error arguments.
$port = (int) ($argv[1] ?? 0);
$count = (int) ($argv[2] ?? 256);
$limit = (float) ($argv[3] ?? 0.75);

$sockets = [];
$start = microtime(true);
for ($i = 0; $i < $count; $i++) {
    $socket = stream_socket_client(
        'tcp://127.0.0.1:' . $port,
        $errno,
        $error,
        1,
        STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
    );
    if (!is_resource($socket)) {
        fwrite(STDERR, "connect failed: $error\n");
        exit(2);
    }
    $sockets[] = $socket;
}

$pending = $sockets;
$deadline = $start + $limit;
while ($pending !== [] && microtime(true) < $deadline) {
    $left = $deadline - microtime(true);
    $read = null;
    $write = $pending;
    $except = null;
    $seconds = (int) $left;
    $microseconds = (int) (($left - $seconds) * 1_000_000);
    if (stream_select($read, $write, $except, $seconds, $microseconds) === false) {
        break;
    }
    foreach ($write as $connected) {
        if (@stream_socket_get_name($connected, true) === false) {
            fwrite(STDERR, "connection failed\n");
            exit(2);
        }
        $pending = array_values(array_filter($pending, fn($socket) => $socket !== $connected));
    }
}

foreach ($sockets as $socket) {
    if (is_resource($socket)) {
        fclose($socket);
    }
}

printf("delayed=%d\n", count($pending));
exit($pending === [] ? 0 : 1);
