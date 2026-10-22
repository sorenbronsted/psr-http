<?php

namespace bronsted;

class HttpIntegrationTest extends SocketTestCase
{
    private mixed $process = null;
    private array $pipes = [];
    private int $port;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('TCP integration tests require proc_open');
        }
        $this->process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/server.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        $this->assertIsResource($this->process);
        fclose($this->pipes[0]);
        stream_set_timeout($this->pipes[1], 3);
        $line = fgets($this->pipes[1]);
        $this->assertNotFalse($line);
        $this->assertMatchesRegularExpression('/^[0-9]+\n$/D', $line);
        $this->port = (int) $line;
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process);
            }
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->process);
        }
        parent::tearDown();
    }

    private function request(string $wire): string
    {
        $errno = 0;
        $error = '';
        // Keep the probe transport independent of the functions intercepted by
        // Brain Monkey in the unit tests.
        $socket = fsockopen('127.0.0.1', $this->port, $errno, $error, 3);
        $this->assertIsResource($socket);
        stream_set_timeout($socket, 3);
        try {
            fwrite($socket, $wire);
            stream_socket_shutdown($socket, STREAM_SHUT_WR);
            $response = stream_get_contents($socket);
            $this->assertFalse(stream_get_meta_data($socket)['timed_out']);
            return $response;
        }
        finally {
            fclose($socket);
        }
    }

    public function testRealServerContinuesAfterHandlerFailureAndRespondsToHalfClosedClients()
    {
        $failed = $this->request("GET /fail HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $this->assertStringStartsWith('HTTP/1.1 500 ', $failed);
        $healthy = $this->request("GET /ok HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $this->assertStringStartsWith('HTTP/1.1 200 ', $healthy);
        $this->assertStringEndsWith("\r\n\r\nok", $healthy);
        $this->request("GET /stop HTTP/1.1\r\n\r\n");
    }

    public function testClientAndServerRoundTripBinaryBodyWithoutContentType()
    {
        $payload = " hello\0\t\r\n";
        $request = $this->factory->createRequest('POST', 'http://127.0.0.1:' . $this->port . '/echo')
            ->withBody($this->factory->createStream($payload));
        $response = null;
        $client = new HttpClient($this->factory, new StreamSocketFactory());
        $loop = FiberLoop::instance();
        $loop->defer(function() use ($client, $request, &$response) {
            $response = $client->sendRequest($request);
        });
        $loop->run();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($payload, $response->getBody()->getContents());
        $this->request("GET /stop HTTP/1.1\r\n\r\n");
    }
}
