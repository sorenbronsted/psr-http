<?php

namespace bronsted;

use ColinODell\PsrTestLogger\TestLogger;
use Psr\Log\LoggerInterface;
use Throwable;

class LogTest extends TestCase
{
    /** @dataProvider levels */
    public function testMessageAndContextAreForwardedUnchanged(string $level)
    {
        $exception = new \RuntimeException('context exception');
        $context = ['id' => 42, 'nested' => ['enabled' => true], 'optional' => null, 'exception' => $exception];

        Log::$level('Processing {id}', $context);

        $this->assertSame([
            ['level' => $level, 'message' => 'Processing {id}', 'context' => $context],
        ], $this->logger->records);
    }

    public static function levels(): array
    {
        return [
            'emergency' => ['emergency'],
            'alert' => ['alert'],
            'critical' => ['critical'],
            'error' => ['error'],
            'warning' => ['warning'],
            'notice' => ['notice'],
            'info' => ['info'],
            'debug' => ['debug'],
        ];
    }

    public function testMissingContextDefaultsToAnEmptyArray()
    {
        Log::info('message');

        $this->assertSame([
            ['level' => 'info', 'message' => 'message', 'context' => []],
        ], $this->logger->records);
    }

    public function testEmptyMessageIsNotDiscarded()
    {
        Log::debug('');

        $this->assertSame([
            ['level' => 'debug', 'message' => '', 'context' => []],
        ], $this->logger->records);
    }

    public function testStringableMessageIsPassedToTheLoggerWithoutCasting()
    {
        $message = new class implements \Stringable {
            public function __toString(): string
            {
                return 'stringable message';
            }
        };
        Log::notice($message, ['id' => 7]);

        $this->assertSame([
            ['level' => 'notice', 'message' => $message, 'context' => ['id' => 7]],
        ], $this->logger->records);
    }

    public function testReplacingLoggerRoutesSubsequentMessagesToTheNewInstance()
    {
        Log::info('before replacement');
        $replacement = new TestLogger();
        try {
            Log::setInstance($replacement);
            Log::warning('after replacement', ['id' => 9]);
        }
        finally {
            Log::setInstance($this->logger);
        }

        $this->assertSame([
            ['level' => 'info', 'message' => 'before replacement', 'context' => []],
        ], $this->logger->records);
        $this->assertSame([
            ['level' => 'warning', 'message' => 'after replacement', 'context' => ['id' => 9]],
        ], $replacement->records);
    }

    /** @dataProvider throwables */
    public function testThrowableMetadataAndEveryTraceFrameAreLoggedInOrder(string $class, string $level)
    {
        $error = $this->captureFailure($class);
        $trace = $error->getTrace();
        $this->assertNotEmpty($trace);
        $this->assertContains('throwFailure', array_column($trace, 'function'));
        $this->assertContains('captureFailure', array_column($trace, 'function'));

        Log::$level($error);

        $records = $this->logger->records;
        $this->assertCount(1 + count($trace), $records);
        $this->assertSame([
            'level' => $level,
            'message' => '{message} {code} {file}:{line}',
            'context' => [
                'message' => 'failure message',
                'code' => 42,
                'file' => __FILE__,
                'line' => $error->getLine(),
            ],
        ], $records[0]);
        foreach ($trace as $index => $frame) {
            $this->assertSame([
                'level' => $level,
                'message' => '{function} {file}:{line}',
                'context' => [
                    'function' => $frame['function'],
                    'file' => $frame['file'] ?? '',
                    'line' => $frame['line'] ?? '',
                ],
            ], $records[$index + 1]);
        }
    }

    public static function throwables(): array
    {
        return [
            'exception' => [\RuntimeException::class, 'error'],
            'engine error' => [\TypeError::class, 'critical'],
        ];
    }

    public function testInternalCallbackFramesWithoutFileOrLineUseEmptyValues()
    {
        try {
            array_map(function() {
                throw new \RuntimeException('callback failure');
            }, [1]);
            $this->fail('The callback must throw');
        }
        catch (\RuntimeException $error) {
            $trace = $error->getTrace();
        }
        $internalFrames = array_filter($trace, fn($frame) => !isset($frame['file']) && !isset($frame['line']));
        $this->assertNotEmpty($internalFrames, 'The fixture must contain a native callback frame');

        Log::warning($error);

        $this->assertCount(1 + count($trace), $this->logger->records);
        foreach ($internalFrames as $index => $frame) {
            $this->assertSame([
                'level' => 'warning',
                'message' => '{function} {file}:{line}',
                'context' => ['function' => $frame['function'], 'file' => '', 'line' => ''],
            ], $this->logger->records[$index + 1]);
        }
    }

    public function testThrowableWithoutTraceProducesOnlyTheMetadataRecord()
    {
        $error = new \RuntimeException('no trace', 7);
        // Exceptions created at global scope can have no trace. Model that case
        // without launching a separate PHP process just to construct the fixture.
        (new \ReflectionProperty(\Exception::class, 'trace'))->setValue($error, []);

        Log::notice($error);

        $this->assertSame([
            [
                'level' => 'notice',
                'message' => '{message} {code} {file}:{line}',
                'context' => [
                    'message' => 'no trace',
                    'code' => 7,
                    'file' => $error->getFile(),
                    'line' => $error->getLine(),
                ],
            ],
        ], $this->logger->records);
    }

    public function testLoggerFailureIsNotSwallowedOrWrapped()
    {
        $error = new \RuntimeException('logger unavailable');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('message', ['id' => 7])->willThrowException($error);
        Log::setInstance($logger);
        try {
            Log::error('message', ['id' => 7]);
            $this->fail('The logger failure must propagate');
        }
        catch (\RuntimeException $caught) {
            $this->assertSame($error, $caught);
        }
        finally {
            Log::setInstance($this->logger);
        }
    }

    private function captureFailure(string $class): Throwable
    {
        try {
            $this->throwFailure($class);
        }
        catch (Throwable $error) {
            return $error;
        }
    }

    private function throwFailure(string $class): void
    {
        throw new $class('failure message', 42);
    }
}
