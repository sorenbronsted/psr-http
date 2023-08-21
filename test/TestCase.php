<?php
namespace bronsted;

use ColinODell\PsrTestLogger\TestLogger;
use DI\Container;
use DI\ContainerBuilder;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase as FrameworkTestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class TestCase extends FrameworkTestCase
{
    protected Container $container;
    protected TestLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $builder = new ContainerBuilder();
        $builder->useAttributes(true);
        $this->container = $builder->build();
        $psr17Factory = new Psr17Factory();
        $this->container->set(ResponseFactoryInterface::class, $psr17Factory);
        $this->container->set(StreamFactoryInterface::class, $psr17Factory);

        $this->logger = new TestLogger();
    }

    protected function mock(string $class): MockObject
    {
        if (!(class_exists($class) || interface_exists($class))) {
            throw new InvalidArgumentException(sprintf('Class not found: %s', $class));
        }

        $mock = $this->getMockBuilder($class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->container->set($class, $mock);

        return $mock;
    }
}