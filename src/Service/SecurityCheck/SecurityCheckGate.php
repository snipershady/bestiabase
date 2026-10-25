<?php

declare(strict_types=1);

namespace App\Service\SecurityCheck;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Chi non ha un account passa dalla verifica di sicurezza prima della home; chi è collegato no.
 */
final readonly class SecurityCheckGate
{
    public function __construct(private SecurityCheckCookie $cookie)
    {
    }

    public function isRequired(Request $request, ?UserInterface $user): bool
    {
        return !$user instanceof UserInterface && !$this->cookie->isValid($request);
    }
}
