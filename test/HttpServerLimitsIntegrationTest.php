<?php

namespace bronsted;

class HttpServerLimitsIntegrationTest extends SocketTestCase
{
    private mixed $process = null;
    private array $pipes = [];
    private array $connections = [];
    private int $port;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('TCP integration tests require proc_open');
        }
        $this->process = proc_open([
            PHP_BINARY, __DIR__ . '/fixtures/server.php',
            json_encode(['headerTimeout' => 0.3, 'requestTimeout' => 0.6, 'maxConnections' => 2]),
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
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
        foreach ($this->connections as $connection) {
            if (is_resource($connection)) {
                fclose($connection);
            }
        }
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->process);
        }
        parent::tearDown();
    }

    private function connect(string $wire)
    {
        $connection = fsockopen('127.0.0.1', $this->port, $errno, $error, 3);
        $this->assertIsResource($connection);
        $this->connections[] = $connection;
        stream_set_timeout($connection, 2);
        $this->assertSame(strlen($wire), fwrite($connection, $wire));

        return $connection;
    }

    private function response($connection): string
    {
        $response = stream_get_contents($connection);
        $this->assertFalse(stream_get_meta_data($connection)['timed_out']);
        fclose($connection);

        return $response;
    }

    private function request(string $path): string
    {
        return $this->response($this->connect("GET {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n"));
    }

    public function testExcessConnectionsAreRejectedAndCompletedRequestsReleaseTheirSlots()
    {
        $first = $this->connect("GET /ok HTTP/1.1\r\nHost: localhost\r\n");
        $second = $this->connect("GET /ok HTTP/1.1\r\nHost: localhost\r\n");
        $rejected = $this->request('/ok');
        $this->assertStringStartsWith('HTTP/1.1 503 ', $rejected);
        $this->assertStringContainsString("Retry-After: 1\r\n", $rejected);

        fwrite($first, "\r\n");
        $this->assertStringStartsWith('HTTP/1.1 200 ', $this->response($first));
        $this->assertStringStartsWith('HTTP/1.1 200 ', $this->request('/ok'));
        fwrite($second, "\r\n");
        $this->assertStringStartsWith('HTTP/1.1 200 ', $this->response($second));
    }

    public function testTimeoutsReleaseConnectionSlotsAndDoNotStopTheListener()
    {
        $first = $this->connect("GET /ok HTTP/1.1\r\nHost: localhost\r\n");
        $second = $this->connect("GET /ok HTTP/1.1\r\nHost: localhost\r\n");
        $this->assertStringStartsWith('HTTP/1.1 503 ', $this->request('/ok'));
        $this->assertStringStartsWith('HTTP/1.1 408 ', $this->response($first));
        $this->assertStringStartsWith('HTTP/1.1 408 ', $this->response($second));
        $this->assertStringStartsWith('HTTP/1.1 200 ', $this->request('/ok'));
    }

    public function testHandlerErrorsAndDisconnectedPeersReleaseTheirSlots()
    {
        $pending = $this->connect("GET /ok HTTP/1.1\r\nHost: localhost\r\n");
        $this->assertStringStartsWith('HTTP/1.1 500 ', $this->request('/fail'));
        $this->assertStringStartsWith('HTTP/1.1 200 ', $this->request('/ok'));
        $this->assertStringStartsWith('HTTP/1.1 500 ', $this->request('/fail'));
        // Half-close forces EOF while parsing; reading the 400 synchronizes slot release.
        stream_socket_shutdown($pending, STREAM_SHUT_WR);
        $this->assertStringStartsWith('HTTP/1.1 400 ', $this->response($pending));
        $this->assertStringStartsWith('HTTP/1.1 200 ', $this->request('/ok'));
    }

    public function testDrippedHeadersStillExpireOnTheRealTcpServer()
    {
        $connection = $this->connect("GET /ok HTTP/1.1\r\nHost: localhost\r\nX-Slow: ");
        $start = microtime(true);
        usleep(80000);
        fwrite($connection, 'a');
        usleep(80000);
        fwrite($connection, 'b');
        $this->assertStringStartsWith('HTTP/1.1 408 ', $this->response($connection));
        $this->assertLessThan(1.0, microtime(true) - $start);
        $this->assertStringStartsWith('HTTP/1.1 200 ', $this->request('/ok'));
    }
}
