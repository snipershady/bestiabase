<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\Altcha\AltchaPurpose;
use App\Service\Altcha\AltchaVerifierInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Il login richiede la soluzione della proof of work ALTCHA, prima ancora di guardare la password:
 * un bot non può provare credenziali a basso costo e il tentativo non rivela se l'utente esiste.
 */
final readonly class LoginAltchaListener
{
    public function __construct(
        private RequestStack $requestStack,
        private AltchaVerifierInterface $verifier,
    ) {
    }

    /**
     * Dopo il controllo CSRF (priorità 512) e prima di quello della password.
     */
    #[AsEventListener(event: CheckPassportEvent::class, priority: 256)]
    public function onCheckPassport(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request?->attributes->get('_route') !== 'app_login') {
            return;
        }

        if (!$this->verifier->verify($request->request->getString('altcha'), AltchaPurpose::Login)) {
            throw new CustomUserMessageAuthenticationException('The security check failed. Wait for it to complete and try again.');
        }
    }
}
