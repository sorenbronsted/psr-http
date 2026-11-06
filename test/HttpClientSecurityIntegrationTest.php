<?php

namespace bronsted;

use Psr\Http\Client\NetworkExceptionInterface;
use Throwable;

class HttpClientSecurityIntegrationTest extends SocketTestCase
{
    private mixed $process = null;
    private array $pipes = [];
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('TCP integration tests require proc_open');
        }
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
        foreach ($this->files as $file) {
            unlink($file);
        }
        parent::tearDown();
    }

    private function peer(string $mode, array $options = []): int
    {
        $this->process = proc_open([
            PHP_BINARY, __DIR__ . '/fixtures/client-server.php', $mode, json_encode($options),
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes);
        $this->assertIsResource($this->process);
        fclose($this->pipes[0]);
        stream_set_timeout($this->pipes[1], 3);
        $line = fgets($this->pipes[1]);
        $this->assertNotFalse($line);
        $this->assertMatchesRegularExpression('/^[0-9]+\n$/D', $line);
        return (int) $line;
    }

    private function request(string $uri, array $options = [], ?string $caFile = null, string $body = '', ?float $cancelAfter = null): array
    {
        if (str_starts_with($uri, 'https:') && !function_exists('stream_socket_enable_crypto')) {
            $this->markTestSkipped('TLS integration tests require OpenSSL');
        }
        $socketFactory = new class($caFile) extends StreamSocketFactory {
            public mixed $connection = null;

            public function __construct(private ?string $caFile)
            {
            }

            public function createStreamSocket(mixed $stream): StreamSocket
            {
                $this->connection = $stream;
                if ($this->caFile !== null) {
                    stream_context_set_option($stream, 'ssl', 'cafile', $this->caFile);
                }
                return parent::createStreamSocket($stream);
            }
        };
        $client = new HttpClient($this->factory, $socketFactory, ...$options);
        $request = $this->factory->createRequest($body === '' ? 'GET' : 'POST', $uri)
            ->withBody($this->factory->createStream($body));
        $response = $error = $timerElapsed = $elapsed = null;
        $loop = FiberLoop::instance();
        $started = hrtime(true) / 1e9;
        $loop->delay(0.03, function() use ($started, &$timerElapsed) {
            $timerElapsed = hrtime(true) / 1e9 - $started;
        });
        if ($cancelAfter !== null) {
            $loop->delay($cancelAfter, fn() => $loop->stop());
        }
        $loop->defer(function() use ($client, $request, $started, &$response, &$error, &$elapsed) {
            try {
                $response = $client->sendRequest($request);
            }
            catch (Throwable $caught) {
                $error = $caught;
            }
            finally {
                $elapsed = hrtime(true) / 1e9 - $started;
            }
        });
        $loop->run();
        $this->assertFalse(is_resource($socketFactory->connection), 'Every completed or failed request must close its socket');
        return [$response, $error, $elapsed, $timerElapsed];
    }

    public function testSlowTlsHandshakeYieldsToTheLoopAndHonorsTheTotalDeadline()
    {
        $port = $this->peer('tls-stall');
        [$response, $error, $elapsed, $timer] = $this->request('https://127.0.0.1:' . $port . '/', ['requestTimeout' => 0.12]);

        $this->assertNull($response);
        $this->assertInstanceOf(NetworkExceptionInterface::class, $error);
        $this->assertLessThan(0.1, $timer, 'TLS negotiation must not block other fibers');
        $this->assertLessThan(0.3, $elapsed);
        $this->assertGreaterThan(0.09, $elapsed);
    }

    public function testSeparateConnectTimeoutAlsoBoundsTheTlsHandshake()
    {
        $port = $this->peer('tls-stall', ['delay' => 1.5]);
        [, $error, $elapsed, $timer] = $this->request('https://127.0.0.1:' . $port . '/', ['timeout' => 1, 'requestTimeout' => 3.0]);

        $this->assertInstanceOf(NetworkExceptionInterface::class, $error);
        $this->assertLessThan(0.15, $timer);
        $this->assertGreaterThan(0.9, $elapsed);
        $this->assertLessThan(1.3, $elapsed);
    }

    public function testZeroConnectTimeoutDoesNotDisableTheOverallDeadline()
    {
        $port = $this->peer('tls-stall');
        [, $error, $elapsed] = $this->request('https://127.0.0.1:' . $port . '/', ['timeout' => 0, 'requestTimeout' => 0.1]);

        $this->assertInstanceOf(NetworkExceptionInterface::class, $error);
        $this->assertLessThan(0.3, $elapsed);
    }

    public function testStoppingTheLoopCancelsTlsAndClosesTheConnection()
    {
        $port = $this->peer('tls-stall');
        [, $error, $elapsed] = $this->request('https://127.0.0.1:' . $port . '/', cancelAfter: 0.06);

        $this->assertInstanceOf(ClientException::class, $error);
        $this->assertInstanceOf(CancelledException::class, $error->getPrevious());
        $this->assertLessThan(0.3, $elapsed);
    }

    /** @dataProvider slowResponses */
    public function testIncomingBytesDoNotResetTheOverallDeadline(string $mode)
    {
        $port = $this->peer($mode);
        [, $error, $elapsed] = $this->request('http://127.0.0.1:' . $port . '/', ['requestTimeout' => 0.12]);

        $this->assertInstanceOf(NetworkExceptionInterface::class, $error);
        $this->assertGreaterThan(0.09, $elapsed);
        $this->assertLessThan(0.3, $elapsed);
    }

    public static function slowResponses(): array
    {
        return [['slow-headers'], ['slow-body'], ['slow-eof'], ['slow-trailers']];
    }

    public function testRequestUploadBackpressureHonorsTheOverallDeadline()
    {
        $port = $this->peer('no-read');
        [, $error, $elapsed] = $this->request('http://127.0.0.1:' . $port . '/',
            ['requestTimeout' => 0.12], body: str_repeat('x', 8 * 1024 * 1024));

        $this->assertInstanceOf(NetworkExceptionInterface::class, $error);
        $this->assertInstanceOf(SocketException::class, $error->getPrevious());
        $this->assertSame(0, $error->getPrevious()->getCode(), 'The deadline must expire while writing, not reading a response');
        $this->assertLessThan(0.3, $elapsed);
    }

    /** @dataProvider oversizedResponses */
    public function testResponseLimitsUsePsrExceptionsAndCloseTheConnection(string $mode)
    {
        $port = $this->peer($mode);
        [, $error] = $this->request('http://127.0.0.1:' . $port . '/', ['maxResponseBodyBytes' => 4]);

        $this->assertInstanceOf(ClientException::class, $error);
        $this->assertNotInstanceOf(NetworkExceptionInterface::class, $error);
        $this->assertInstanceOf(\LengthException::class, $error->getPrevious());
        $this->assertSame('http://127.0.0.1:' . $port . '/', (string) $error->getRequest()->getUri());
    }

    public static function oversizedResponses(): array
    {
        return [['oversized-fixed'], ['oversized-chunked'], ['oversized-eof']];
    }

    public function testRefusedTcpConnectionIsReportedPromptly()
    {
        $listener = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($listener);
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);
        [, $error, $elapsed] = $this->request('http://127.0.0.1:' . $port . '/', ['requestTimeout' => 2.0]);

        $this->assertInstanceOf(NetworkExceptionInterface::class, $error);
        $this->assertLessThan(0.5, $elapsed);
    }

    private function certificate(int $days = 1): array
    {
        if (!function_exists('openssl_pkey_new')) {
            $this->markTestSkipped('TLS certificate tests require OpenSSL');
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'localhost'], $key);
        $this->assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, $days);
        $this->assertNotFalse($certificate);
        $this->assertTrue(openssl_x509_export($certificate, $certificatePem));
        $this->assertTrue(openssl_pkey_export($key, $keyPem));
        $certificateFile = tempnam(sys_get_temp_dir(), 'psr-http-cert-');
        $keyFile = tempnam(sys_get_temp_dir(), 'psr-http-key-');
        $this->files[] = $certificateFile;
        $this->files[] = $keyFile;
        file_put_contents($certificateFile, $certificatePem);
        file_put_contents($keyFile, $keyPem);
        return ['certificate' => $certificateFile, 'key' => $keyFile];
    }

    public function testTlsAcceptsATrustedCertificateForTheCorrectHost()
    {
        $certificate = $this->certificate();
        $port = $this->peer('tls', $certificate);
        [$response, $error] = $this->request('https://localhost:' . $port . '/', caFile: $certificate['certificate']);

        $this->assertNull($error);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getBody()->getContents());
    }

    /** @dataProvider invalidCertificates */
    public function testTlsRejectsInvalidCertificates(string $reason)
    {
        $certificate = $this->certificate($reason === 'expired' ? 0 : 1);
        if ($reason === 'expired') {
            // Zero-day certificates expire at issuance; advance past their
            // whole-second notAfter value on both PHP 8.1 and newer runtimes.
            sleep(1);
        }
        $port = $this->peer('tls', $certificate);
        $host = $reason === 'wrong host' ? '127.0.0.1' : 'localhost';
        [, $error] = $this->request('https://' . $host . ':' . $port . '/',
            caFile: $reason === 'untrusted' ? null : $certificate['certificate']);

        $this->assertInstanceOf(NetworkExceptionInterface::class, $error);
        $this->assertStringContainsString('TLS', $error->getMessage());
    }

    public static function invalidCertificates(): array
    {
        return [['untrusted'], ['wrong host'], ['expired']];
    }

    public function testTlsNeverFallsBackToSendingPlaintextHttp()
    {
        $port = $this->peer('plaintext-probe');
        [, $error] = $this->request('https://127.0.0.1:' . $port . '/');
        $hex = trim(stream_get_contents($this->pipes[1]));

        $this->assertInstanceOf(NetworkExceptionInterface::class, $error);
        $this->assertStringStartsWith('1603', $hex, 'The peer must receive a TLS ClientHello, not a plaintext request');
    }
}
