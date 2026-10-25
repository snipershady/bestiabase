<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Header di sicurezza su ogni risposta. La CSP non ammette script né stili inline: quelli
 * necessari (importmap di AssetMapper, stile della barra di Turbo) ricevono un nonce casuale
 * generato per ogni richiesta ({@see self::nonce()}, funzione Twig `csp_nonce()`).
 *
 * HSTS non è qui: va impostato dal web server o dal proxy che termina TLS (vedi SETUP.md).
 */
final readonly class SecurityHeadersListener
{
    public const string NONCE_ATTRIBUTE = '_csp_nonce';

    public function __construct(
        #[Autowire(param: 'kernel.debug')]
        private bool $debug = false,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 1024)]
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getRequest()->attributes->set(self::NONCE_ATTRIBUTE, bin2hex(random_bytes(16)));
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $nonce = $event->getRequest()->attributes->getString(self::NONCE_ATTRIBUTE);
        $headers = $event->getResponse()->headers;

        $headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            \sprintf("script-src 'self' 'nonce-%s'", $nonce),
            \sprintf("style-src 'self' 'nonce-%s'", $nonce),
            // data: per la favicon SVG inline.
            "img-src 'self' data:",
            "font-src 'self'",
            // Il widget ALTCHA risolve la proof of work in worker creati da blob: (solo i worker).
            "worker-src 'self' blob:",
            // fetch dello stato del job (polling).
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]));

        if ($this->debug) {
            // La web debug toolbar genera nonce suoi e li aggiunge alla CSP: Turbo, che ricrea gli script
            // del body con il nonce della pagina, ne bloccherebbe quelli della toolbar. Con questi header
            // il profiler riusa il nostro nonce (e toglie gli header prima di rispondere).
            $headers->set('X-SymfonyProfiler-Script-Nonce', $nonce);
            $headers->set('X-SymfonyProfiler-Style-Nonce', $nonce);
        }

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
    }
}
