<?php

declare(strict_types=1);

namespace App\Twig;

use App\Security\SecurityHeadersListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

final readonly class SecurityExtension
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    /**
     * Nonce CSP della richiesta corrente, da mettere sugli script e gli stili inline ammessi.
     */
    #[AsTwigFunction('csp_nonce')]
    public function cspNonce(): string
    {
        return $this->requestStack->getMainRequest()?->attributes->getString(SecurityHeadersListener::NONCE_ATTRIBUTE) ?? '';
    }
}
