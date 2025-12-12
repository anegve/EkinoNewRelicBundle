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

namespace Ekino\NewRelicBundle\Tests\Listener;

use Ekino\NewRelicBundle\Listener\ResponseListener;
use Ekino\NewRelicBundle\NewRelic\Config;
use Ekino\NewRelicBundle\NewRelic\NewRelicInteractorInterface;
use Ekino\NewRelicBundle\Twig\NewRelicExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class ResponseListenerTest extends TestCase
{
    /**
     * @var (NewRelicInteractorInterface&\PHPUnit\Framework\MockObject\MockObject)|\PHPUnit\Framework\MockObject\MockObject
     */
    private $interactor;

    /**
     * @var (Config&\PHPUnit\Framework\MockObject\MockObject)|\PHPUnit\Framework\MockObject\MockObject
     */
    private $newRelic;

    /**
     * @var (NewRelicExtension&\PHPUnit\Framework\MockObject\MockObject)|\PHPUnit\Framework\MockObject\MockObject
     */
    private $extension;

    protected function setUp(): void
    {
        $this->interactor = $this->getMockBuilder(NewRelicInteractorInterface::class)->getMock();
        $this->newRelic = $this->getMockBuilder(Config::class)
            ->onlyMethods(['getCustomEvents', 'getCustomMetrics', 'getCustomParameters'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->extension = $this->getMockBuilder(NewRelicExtension::class)
            ->onlyMethods(['isHeaderCalled', 'isFooterCalled', 'isUsed'])
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function testOnKernelResponseOnlyMasterRequestsAreProcessed()
    {
        $event = $this->createFilterResponseEventDummy(null, null, HttpKernelInterface::SUB_REQUEST);

        $object = new ResponseListener($this->newRelic, $this->interactor);
        $object->onKernelResponse($event);

        $this->newRelic->expects($this->never())->method('getCustomMetrics');
    }

    public function testOnKernelResponseWithOnlyCustomMetricsAndParameters()
    {
        $events = [
            'WidgetSale' => [
                [
                    'color' => 'red',
                    'weight' => 12.5,
                ],
                [
                    'color' => 'blue',
                    'weight' => 12.5,
                ],
            ],
        ];

        $metrics = [
            'foo_a' => 4.7,
            'foo_b' => 11,
        ];

        $parameters = [
            'foo_1' => 'bar_1',
            'foo_2' => 'bar_2',
        ];

        $this->newRelic->expects($this->once())->method('getCustomEvents')->willReturn($events);
        $this->newRelic->expects($this->once())->method('getCustomMetrics')->willReturn($metrics);
        $this->newRelic->expects($this->once())->method('getCustomParameters')->willReturn($parameters);

        $metricMatcher = $this->exactly(2);
        $this->interactor->expects($metricMatcher)->method('addCustomMetric')->willReturnCallback(
            function (string $name, float $value) use ($metricMatcher) {
                match ($metricMatcher->numberOfInvocations()) {
                    1 => $this->assertEquals(['foo_a', 4.7], [$name, $value]),
                    2 => $this->assertEquals(['foo_b', 11], [$name, $value]),
                };
                return true;
            }
        );
        $paramMatcher = $this->exactly(2);
        $this->interactor->expects($paramMatcher)->method('addCustomParameter')->willReturnCallback(
            function (string $name, $value) use ($paramMatcher) {
                match ($paramMatcher->numberOfInvocations()) {
                    1 => $this->assertEquals(['foo_1', 'bar_1'], [$name, $value]),
                    2 => $this->assertEquals(['foo_2', 'bar_2'], [$name, $value]),
                };
                return true;
            }
        );
        $eventMatcher = $this->exactly(2);
        $this->interactor->expects($eventMatcher)->method('addCustomEvent')->willReturnCallback(
            function (string $name, array $attributes) use ($eventMatcher) {
                match ($eventMatcher->numberOfInvocations()) {
                    1 => $this->assertEquals(['WidgetSale', ['color' => 'red', 'weight' => 12.5]], [$name, $attributes]),
                    2 => $this->assertEquals(['WidgetSale', ['color' => 'blue', 'weight' => 12.5]], [$name, $attributes]),
                };
            }
        );

        $event = $this->createFilterResponseEventDummy();

        $object = new ResponseListener($this->newRelic, $this->interactor, false);
        $object->onKernelResponse($event);
    }

    public function testOnKernelResponseInstrumentDisabledInRequest()
    {
        $this->setupNoCustomMetricsOrParameters();

        $this->interactor->expects($this->once())->method('disableAutoRUM');

        $event = $this->createFilterResponseEventDummy();

        $object = new ResponseListener($this->newRelic, $this->interactor, true);
        $object->onKernelResponse($event);
    }

    public function testSymfonyCacheEnabled()
    {
        $this->setupNoCustomMetricsOrParameters();

        $this->interactor->expects($this->once())->method('endTransaction');

        $event = $this->createFilterResponseEventDummy();

        $object = new ResponseListener($this->newRelic, $this->interactor, false, true);
        $object->onKernelResponse($event);
    }

    public function testSymfonyCacheDisabled()
    {
        $this->setupNoCustomMetricsOrParameters();

        $this->interactor->expects($this->never())->method('endTransaction');

        $event = $this->createFilterResponseEventDummy();

        $object = new ResponseListener($this->newRelic, $this->interactor, false, false);
        $object->onKernelResponse($event);
    }

    #[DataProvider('providerOnKernelResponseOnlyInstrumentHTMLResponses')]
    public function testOnKernelResponseOnlyInstrumentHTMLResponses($content, $expectsSetContent, $contentType)
    {
        $this->setupNoCustomMetricsOrParameters();

        $this->interactor->expects($this->once())->method('disableAutoRUM');
        $this->interactor->expects($this->any())->method('getBrowserTimingHeader')->willReturn('__Timing_Header__');
        $this->interactor->expects($this->any())->method('getBrowserTimingFooter')->willReturn('__Timing_Feader__');

        $response = $this->createResponseMock($content, $expectsSetContent, $contentType);
        $event = $this->createFilterResponseEventDummy(null, $response);

        $object = new ResponseListener($this->newRelic, $this->interactor, true);
        $object->onKernelResponse($event);
    }

    public static function providerOnKernelResponseOnlyInstrumentHTMLResponses(): array
    {
        return [
            // unsupported content types
            [null, null, 'text/xml'],
            [null, null, 'text/plain'],
            [null, null, 'application/json'],

            ['content', 'content', 'text/html'],
            ['<div class="head">head</div>', '<div class="head">head</div>', 'text/html'],
            ['<header>content</header>', '<header>content</header>', 'text/html'],

            // head, body tags
            ['<head><title /></head>', '<head>__Timing_Header__<title /></head>', 'text/html'],
            ['<body><div /></body>', '<body><div />__Timing_Feader__</body>', 'text/html'],
            ['<head><title /></head><body><div /></body>', '<head>__Timing_Header__<title /></head><body><div />__Timing_Feader__</body>', 'text/html'],

            // with charset
            ['<head><title /></head><body><div /></body>', '<head>__Timing_Header__<title /></head><body><div />__Timing_Feader__</body>', 'text/html; charset=UTF-8'],
        ];
    }

    public function testInteractionWithTwigExtensionHeader()
    {
        $this->newRelic->expects($this->never())->method('getCustomMetrics');
        $this->newRelic->expects($this->never())->method('getCustomParameters');
        $this->newRelic->expects($this->once())->method('getCustomEvents')->willReturn([]);

        $this->interactor->expects($this->never())->method('disableAutoRUM');
        $this->interactor->expects($this->never())->method('getBrowserTimingHeader');
        $this->interactor->expects($this->once())->method('getBrowserTimingFooter')->willReturn('__Timing_Feader__');

        $this->extension->expects($this->exactly(2))->method('isUsed')->willReturn(true);
        $this->extension->expects($this->once())->method('isHeaderCalled')->willReturn(true);
        $this->extension->expects($this->once())->method('isFooterCalled')->willReturn(false);

        $request = $this->createRequestMock(true);
        $response = $this->createResponseMock('content', 'content', 'text/html');
        $event = $this->createFilterResponseEventDummy($request, $response);

        $object = new ResponseListener($this->newRelic, $this->interactor, true, false, $this->extension);
        $object->onKernelResponse($event);
    }

    public function testInteractionWithTwigExtensionFooter()
    {
        $this->newRelic->expects($this->never())->method('getCustomMetrics');
        $this->newRelic->expects($this->never())->method('getCustomParameters');
        $this->newRelic->expects($this->once())->method('getCustomEvents')->willReturn([]);

        $this->interactor->expects($this->never())->method('disableAutoRUM');
        $this->interactor->expects($this->once())->method('getBrowserTimingHeader')->willReturn('__Timing_Feader__');
        $this->interactor->expects($this->never())->method('getBrowserTimingFooter');

        $this->extension->expects($this->exactly(2))->method('isUsed')->willReturn(true);
        $this->extension->expects($this->once())->method('isHeaderCalled')->willReturn(false);
        $this->extension->expects($this->once())->method('isFooterCalled')->willReturn(true);

        $request = $this->createRequestMock(true);
        $response = $this->createResponseMock('content', 'content', 'text/html');
        $event = $this->createFilterResponseEventDummy($request, $response);

        $object = new ResponseListener($this->newRelic, $this->interactor, true, false, $this->extension);
        $object->onKernelResponse($event);
    }

    public function testInteractionWithTwigExtensionHeaderFooter()
    {
        $this->newRelic->expects($this->never())->method('getCustomMetrics');
        $this->newRelic->expects($this->never())->method('getCustomParameters');
        $this->newRelic->expects($this->once())->method('getCustomEvents')->willReturn([]);

        $this->interactor->expects($this->never())->method('disableAutoRUM');
        $this->interactor->expects($this->never())->method('getBrowserTimingHeader');
        $this->interactor->expects($this->never())->method('getBrowserTimingFooter');

        $this->extension->expects($this->exactly(2))->method('isUsed')->willReturn(true);
        $this->extension->expects($this->once())->method('isHeaderCalled')->willReturn(true);
        $this->extension->expects($this->once())->method('isFooterCalled')->willReturn(true);

        $request = $this->createRequestMock(true);
        $response = $this->createResponseMock('content', 'content', 'text/html');
        $event = $this->createFilterResponseEventDummy($request, $response);

        $object = new ResponseListener($this->newRelic, $this->interactor, true, false, $this->extension);
        $object->onKernelResponse($event);
    }

    private function setUpNoCustomMetricsOrParameters()
    {
        $this->newRelic->expects($this->once())->method('getCustomEvents')->willReturn([]);
        $this->newRelic->expects($this->once())->method('getCustomMetrics')->willReturn([]);
        $this->newRelic->expects($this->once())->method('getCustomParameters')->willReturn([]);

        $this->interactor->expects($this->never())->method('addCustomEvent');
        $this->interactor->expects($this->never())->method('addCustomMetric');
        $this->interactor->expects($this->never())->method('addCustomParameter');
    }

    private function createRequestMock($instrumentEnabled = true)
    {
        $mock = new Request();

        $attributes = $this->getMockBuilder(ParameterBag::class)->getMock();
        $attributes->method('get')->willReturn($instrumentEnabled);

        $mock->attributes = $attributes;

        return $mock;
    }

    private function createResponseMock($content = null, $expectsSetContent = null, $contentType = 'text/html')
    {
        $mock = $this->getMockBuilder(Response::class)
            ->onlyMethods(['getContent', 'setContent'])
            ->getMock();

        $responseHeaders = $this->getMockBuilder(ResponseHeaderBag::class)->getMock();
        $responseHeaders->method('get')->willReturn($contentType);

        $mock->headers = $responseHeaders;

        $mock->expects($content ? $this->any() : $this->never())->method('getContent')->willReturn($content ?? false);

        if ($expectsSetContent) {
            $setContentMatcher = $this->exactly(2);
            $mock->expects($setContentMatcher)->method('setContent')->willReturnCallback(
                function (string $content) use ($setContentMatcher, $expectsSetContent, $mock) {
                    match ($setContentMatcher->numberOfInvocations()) {
                        1 => $this->assertEquals('', $content),
                        2 => $this->assertEquals($expectsSetContent, $content),
                    };
                    return $mock;
                }
            );
        } else {
            $mock->expects($this->never())->method('setContent');
        }

        return $mock;
    }

    private function createFilterResponseEventDummy(?Request $request = null, ?Response $response = null, int $requestType = HttpKernelInterface::MAIN_REQUEST)
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        $event = new ResponseEvent($kernel, $request ?? new Request(), $requestType, $response ?? new Response());

        return $event;
    }
}
