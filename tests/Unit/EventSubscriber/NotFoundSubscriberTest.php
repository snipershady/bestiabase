<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\NotFoundSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

#[CoversClass(NotFoundSubscriber::class)]
final class NotFoundSubscriberTest extends TestCase
{
    public function testNotFoundBecomesARedirectToTheTrapAndStopsPropagating(): void
    {
        $event = $this->event(new NotFoundHttpException());

        $this->subscriber()->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/nopainnogain', $response->getTargetUrl());
        self::assertTrue($event->isPropagationStopped(), 'the 404 must not reach the logger');
    }

    public function testOtherExceptionsAreLeftAlone(): void
    {
        $event = $this->event(new RuntimeException('boom'));

        $this->subscriber()->onKernelException($event);

        self::assertNotInstanceOf(RedirectResponse::class, $event->getResponse());
        self::assertFalse($event->isPropagationStopped());
    }

    private function subscriber(): NotFoundSubscriber
    {
        $urlGenerator = self::createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/nopainnogain');

        return new NotFoundSubscriber($urlGenerator);
    }

    private function event(Throwable $throwable): ExceptionEvent
    {
        return new ExceptionEvent(self::createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $throwable);
    }
}
