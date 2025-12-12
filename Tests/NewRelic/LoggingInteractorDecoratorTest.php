<?php

declare(strict_types=1);

/*
 * This file is part of Ekino New Relic bundle.
 *
 * (c) Ekino - Thomas Rabaix <thomas.rabaix@ekino.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ekino\NewRelicBundle\Tests\NewRelic;

use Ekino\NewRelicBundle\NewRelic\LoggingInteractorDecorator;
use Ekino\NewRelicBundle\NewRelic\NewRelicInteractorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LoggingInteractorDecoratorTest extends TestCase
{
    #[DataProvider('provideMethods')]
    public function testGeneric(string $method, array $arguments, $return)
    {
        $logger = $this->createMock(LoggerInterface::class);
        $decorated = $this->createMock(LoggingInteractorDecorator::class);
        $interactor = new LoggingInteractorDecorator($decorated, $logger);

        $logger->expects($this->once())->method('debug');
        $call = $decorated->expects($this->once())->method($method)
            ->with(...$arguments);
        if (null !== $return) {
            $call->willReturn($return);
        }

        $result = $interactor->$method(...$arguments);

        $this->assertSame($return, $result);
    }

    public static function provideMethods(): \Generator
    {
        $reflection = new \ReflectionClass(NewRelicInteractorInterface::class);
        foreach ($reflection->getMethods() as $method) {
            if (!$method->isPublic()) {
                continue;
            }
            if ($method->isStatic()) {
                continue;
            }

            $arguments = array_map(function (\ReflectionParameter $parameter) {
                return self::getTypeStub($parameter->getType());
            }, $method->getParameters());

            $return = $method->hasReturnType() ? self::getTypeStub($method->getReturnType()) : null;

            yield [$method->getName(), $arguments, $return];
        }
    }

    private static function getTypeStub(?\ReflectionType $type): mixed
    {
        if (null === $type) {
            return uniqid('', true);
        }

        return match ($type->getName()) {
            'string' => uniqid('', true),
            'bool' => (bool) rand(0, 1),
            'float' => rand(0, 100) / rand(1, 10),
            'int' => rand(0, 100),
            'void' => null,
            'Throwable' => new \Exception(),
            'callable' => function () {},
            'array' => array_fill(0, 2, uniqid('', true)),
            default => throw new \UnexpectedValueException('Unknown type. '.$type->getName()),
        };
    }
}
