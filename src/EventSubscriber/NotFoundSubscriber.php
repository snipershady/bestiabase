<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Ogni NotFoundHttpException (rotta inesistente o 404 sollevato da un controller) diventa un
 * reindirizzamento silenzioso a "nopainnogain". Gira prima del logging di Symfony
 * (priorità 0) e ferma la propagazione: i 404 dei bot non finiscono nei log.
 */
final readonly class NotFoundSubscriber
{
    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof NotFoundHttpException) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_nopainnogain')));
        $event->stopPropagation();
    }
}
