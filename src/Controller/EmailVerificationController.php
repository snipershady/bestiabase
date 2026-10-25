<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Account\EmailVerificationResult;
use App\Service\Account\EmailVerifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Conferma dell'indirizzo e-mail di chi si è registrato.
 */
final class EmailVerificationController extends AbstractController
{
    /**
     * Il link ricevuto per e-mail: firmato e a scadenza, funziona anche da un altro dispositivo.
     */
    #[Route('/verify-email', name: 'app_verify_email', methods: ['GET'])]
    public function verify(Request $request, EmailVerifier $verifier): RedirectResponse
    {
        match ($verifier->confirm($request)) {
            EmailVerificationResult::Verified => $this->addFlash('success', 'Your email address is confirmed. An administrator will enable your account.'),
            EmailVerificationResult::AlreadyVerified => $this->addFlash('info', 'Your email address was already confirmed.'),
            EmailVerificationResult::Expired => $this->addFlash('danger', 'This confirmation link has expired. Sign in to receive a new one.'),
            EmailVerificationResult::Invalid => $this->addFlash('danger', 'This confirmation link is not valid.'),
        };

        return $this->redirectToRoute('app_search');
    }

    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[Route('/account/verify-email/resend', name: 'app_resend_verification', methods: ['POST'])]
    public function resend(
        Request $request,
        #[CurrentUser]
        User $user,
        EmailVerifier $verifier,
        #[Target('email_verification')]
        RateLimiterFactoryInterface $emailVerificationLimiter,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('resend-verification', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($user->isEmailVerified()) {
            return $this->redirectToRoute('app_search');
        }

        if (!$emailVerificationLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            $this->addFlash('danger', 'Too many requests. Try again later.');

            return $this->redirectToRoute('app_search');
        }

        $verifier->sendLink($user);
        $this->addFlash('success', 'We sent you a new confirmation email.');

        return $this->redirectToRoute('app_search');
    }
}
